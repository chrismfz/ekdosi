<?php

namespace App\Console\Commands;

use App\Exceptions\Aade\AadeAfmNotFound;
use App\Exceptions\Aade\AadeCredentialsInvalid;
use App\Exceptions\Aade\AadeRegistryException;
use App\Exceptions\Aade\AadeUnreachable;
use App\Models\Company;
use App\Models\Customer;
use App\Services\AadeRegistryLookup;
use App\Support\Afm;
use Illuminate\Console\Command;

/**
 * #8 «Ανενεργό ΑΦΜ»: periodically re-check the AADE/GSIS registry activity status
 * of customers (ενεργό/ανενεργό ΑΦΜ) and persist it (Customer::recordAadeStatus)
 * so a closed-business counterpart surfaces on the καρτέλα/λίστα badge WITHOUT an
 * operator opening the on-demand «Διασταύρωση» modal.
 *
 *   php artisan customers:refresh-aade-status [--tenant=SLUG] [--limit=50]
 *       [--stale-days=90] [--force] [--throttle-ms=500]
 *
 * BOUNDED + gentle by design (GSIS has rate limits): only GR tenants with GSIS
 * credentials, only customers with a Greek ΑΦΜ identity, only the never-checked or
 * stale ones (oldest first), capped at --limit per tenant, with a small pause
 * between live calls. Informational ONLY — never blocks issuing, never touches the
 * operator's own `is_active` flag.
 *
 * Scheduler-gated (EKDOSI_SCHEDULE_AADE_STATUS_REFRESH, default OFF); safe to run
 * manually anytime. Idempotent — re-running just refreshes «τελευταίος έλεγχος».
 *
 * Exit codes: 0 success · 6 unknown --tenant slug · 7 at least one lookup errored.
 */
class RefreshCustomerAadeStatus extends Command
{
    protected $signature = 'customers:refresh-aade-status
        {--tenant= : Company slug (manual). Default: every GR tenant that OPTED IN (aade_status_auto_refresh).}
        {--limit=50 : Max customers to check per tenant this run — kept small to respect GSIS quotas.}
        {--stale-days=90 : Re-check the never-checked + those older than N days (--force overrides).}
        {--force : Re-check even recently-checked customers.}
        {--throttle-ms=500 : Pause between live GSIS calls, in ms (0 to disable).}';

    protected $description = 'Re-check the AADE/GSIS registry status (ενεργό/ανενεργό ΑΦΜ) of customers, in bounded batches.';

    public function handle(): int
    {
        $slug = (string) $this->option('tenant');
        if ($slug !== '') {
            $tenant = Company::query()->where('slug', $slug)->first();
            if ($tenant === null) {
                $this->error("No tenant with slug='{$slug}'.");

                return 6;
            }
            // Manual run for one tenant: the operator explicitly asked, so the
            // per-tenant opt-in toggle is not required (creds still are, below).
            $tenants = collect([$tenant]);
        } else {
            // Scheduled sweep: ONLY tenants that opted in (the knob next to their
            // GSIS credentials). Keeps the background job from ever touching a
            // tenant that didn't ask for it — belt to the global scheduler flag.
            $tenants = Company::query()
                ->where('country_code', 'GR')
                ->where('aade_status_auto_refresh', true)
                ->get();
        }

        $limit = max(1, (int) $this->option('limit'));
        $staleDays = max(0, (int) $this->option('stale-days'));
        $force = (bool) $this->option('force');
        $throttleUs = max(0, (int) $this->option('throttle-ms')) * 1000;

        $checked = 0;
        $active = 0;
        $inactive = 0;
        $errors = 0;

        foreach ($tenants as $tenant) {
            // Mirror the καρτέλα crosscheck gate: GR + GSIS creds configured.
            if ($tenant->country_code !== 'GR' || blank($tenant->gsis_username) || blank($tenant->gsis_password)) {
                continue;
            }
            $lookup = app(AadeRegistryLookup::class, ['tenant' => $tenant]);

            $query = Customer::query()
                ->where('company_id', $tenant->id)
                ->whereNotNull('afm_key');   // has an ΑΦΜ identity (parked rows have null)
            if (! $force) {
                $cutoff = now()->subDays($staleDays);
                $query->where(fn ($w) => $w->whereNull('aade_status_checked_at')
                    ->orWhere('aade_status_checked_at', '<', $cutoff));
            }
            $customers = $query
                ->orderByRaw('aade_status_checked_at IS NULL DESC')   // never-checked first
                ->orderBy('aade_status_checked_at')                    // then the stalest
                ->limit($limit)
                ->get();

            foreach ($customers as $customer) {
                $afm = Afm::uniqueKey($customer->afm);
                if ($afm === null || ! ctype_digit($afm)) {
                    continue;   // no Greek ΑΦΜ (foreign VAT / placeholder) → GSIS can't answer
                }

                try {
                    $record = $lookup->findByAfm($afm);
                    $customer->recordAadeStatus($record->active, $record->statusDescr);
                    $record->active ? $active++ : $inactive++;
                    $checked++;
                } catch (AadeAfmNotFound) {
                    // Absent from the registry (closed / never valid) → mark inactive.
                    $customer->recordAadeStatus(false, 'ΑΦΜ δεν βρέθηκε στο μητρώο ΑΑΔΕ');
                    $inactive++;
                    $checked++;
                } catch (AadeCredentialsInvalid $e) {
                    // Every remaining lookup for this tenant would fail identically.
                    $this->warn("Tenant {$tenant->slug}: GSIS credentials invalid — {$e->getMessage()}. Skipping tenant.");
                    $errors++;
                    break;
                } catch (AadeUnreachable $e) {
                    // GSIS unreachable OR daily quota exhausted (both surface as
                    // AadeUnreachable) → every further call this run would fail too AND
                    // keep hammering an already-blocked account. STOP this tenant now
                    // (respect the quota / avoid a ban); the next scheduled run resumes
                    // from the stalest rows.
                    $this->warn("Tenant {$tenant->slug}: GSIS unreachable/quota — {$e->getMessage()}. Stopping tenant.");
                    $errors++;
                    break;
                } catch (AadeRegistryException $e) {
                    // Any other registry error (e.g. a one-off parse failure) — skip
                    // this customer, keep going.
                    $errors++;
                }

                if ($throttleUs > 0) {
                    usleep($throttleUs);
                }
            }
        }

        $this->info(sprintf(
            'AADE status: checked %d (%d ενεργά, %d ανενεργά), %d errors.',
            $checked, $active, $inactive, $errors,
        ));

        return $errors > 0 ? 7 : Command::SUCCESS;
    }
}
