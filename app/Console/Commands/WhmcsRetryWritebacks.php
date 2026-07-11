<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\PendingWhmcsInvoice;
use App\Services\Whmcs\WhmcsWritebackService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * WH-7 — re-attempt WHMCS MARK write-backs that previously FAILED.
 *
 *   php artisan whmcs:retry-writebacks [--tenant=SLUG] [--dry-run]
 *
 * When a myDATA MARK is filed but pushing it back to WHMCS fails (bridge down,
 * transient network), the pending row is left at whmcs_writeback_state='failed'.
 * The AADE filing is the legal truth and is untouched; only the WHMCS badge /
 * ledger bookkeeping is missing. This is the unattended safety net that sweeps
 * those rows and re-runs the write-back (the operator can also retry a single
 * row from the WHMCS inbox «Επανάληψη επιστροφής ΜΑΡΚ» action).
 *
 * Does NOT touch AADE — it only re-pushes an already-filed MARK, so it needs no
 * two-key AADE-safety arming. Reuses WhmcsWritebackService::retryWriteback(),
 * which only writes the two audit-freeze-whitelisted columns and skips split
 * rows. Index-backed by (company_id, whmcs_writeback_state).
 *
 * Can be scheduled behind a new EKDOSI_SCHEDULE_* flag; inert until the
 * scheduler + a queue worker are live (same as the rest of routes/console.php).
 *
 * Exit codes:
 *   0 success (incl. nothing to do)
 *   2 invalid usage (--tenant slug unknown)
 */
class WhmcsRetryWritebacks extends Command
{
    protected $signature = 'whmcs:retry-writebacks
        {--tenant= : Limit to one Company slug (default: every WHMCS-configured tenant).}
        {--dry-run : List what WOULD be retried; push nothing.}';

    protected $description = 'WHMCS bridge: re-attempt failed MARK write-backs (whmcs_writeback_state=failed). Does not touch AADE.';

    public function handle(WhmcsWritebackService $writeback): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $slug = (string) $this->option('tenant');

        $tenants = $this->resolveTenants($slug);
        if ($tenants === null) {
            return self::INVALID;
        }
        if ($tenants->isEmpty()) {
            $this->info('No WHMCS-configured tenants. Nothing to do.');

            return self::SUCCESS;
        }

        $totalOk = 0;
        $totalFail = 0;
        $totalCandidates = 0;

        foreach ($tenants as $tenant) {
            $rows = PendingWhmcsInvoice::query()
                ->where('company_id', $tenant->id)
                ->where('whmcs_writeback_state', PendingWhmcsInvoice::WRITEBACK_FAILED)
                ->where('status', '!=', PendingWhmcsInvoice::STATUS_SPLIT)
                ->orderBy('id')
                ->get();

            if ($rows->isEmpty()) {
                $this->line("• {$tenant->slug}: none");

                continue;
            }

            $totalCandidates += $rows->count();

            foreach ($rows as $pending) {
                if ($dryRun) {
                    $this->line("  [dry] {$tenant->slug} · WHMCS #{$pending->whmcs_invoice_id} (MARK {$pending->mydata_mark})");

                    continue;
                }

                try {
                    $state = $writeback->retryWriteback($pending);
                    if ($state === PendingWhmcsInvoice::WRITEBACK_SUCCEEDED) {
                        $totalOk++;
                    } else {
                        // 'failed' again, or 'skipped' (bridge not configured).
                        $totalFail++;
                    }
                } catch (Throwable $e) {
                    $totalFail++;
                    Log::warning('whmcs:retry-writebacks — row not retryable', [
                        'company_id' => $tenant->id,
                        'slug' => $tenant->slug,
                        'pending_id' => $pending->id,
                        'whmcs_invoice_id' => $pending->whmcs_invoice_id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $this->info("✓ {$tenant->slug}: ".($dryRun ? 'would retry '.$rows->count() : "retried {$rows->count()}"));
        }

        $verb = $dryRun ? 'would retry' : 'retried';
        $this->newLine();
        $this->info("Done. {$verb} {$totalCandidates} failed write-back(s)".
            ($dryRun ? '.' : "; {$totalOk} succeeded, {$totalFail} still failing."));

        return self::SUCCESS;
    }

    /**
     * Resolve the target tenants. Returns null on an unknown --tenant slug
     * (caller maps that to exit 2). hasWhmcsIntegration() checks encrypted
     * columns, so the all-tenants case filters in PHP.
     *
     * @return Collection<int, Company>|null
     */
    private function resolveTenants(string $slug): ?Collection
    {
        if ($slug !== '') {
            $tenant = Company::query()->where('slug', $slug)->first();
            if ($tenant === null) {
                $this->error("No tenant with slug='{$slug}'.");

                return null;
            }
            if (! $tenant->hasWhmcsIntegration()) {
                $this->warn("Tenant '{$slug}' has no WHMCS integration configured. Nothing to do.");

                return collect();
            }

            return collect([$tenant]);
        }

        return Company::query()
            ->get()
            ->filter(fn (Company $c) => $c->hasWhmcsIntegration())
            ->values();
    }
}
