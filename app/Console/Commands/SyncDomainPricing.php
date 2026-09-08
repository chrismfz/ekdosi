<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesDomainCompanies;
use App\Models\DomainTld;
use App\Services\Domains\DomainPricingSyncService;
use Illuminate\Console\Command;

/**
 * domains:sync-pricing (Πυλώνας A / A2c) — pull the registrar COST per TLD
 * into domain_tld_prices.cost for domain-enabled tenants. READ-ONLY at the
 * registrar; locally it writes ONLY the cost column (sell price + is_enabled
 * stay operator decisions — DomainPricingSyncService has the discipline).
 * Manual-run (costs change rarely); not on the scheduler.
 *
 * CLI tenancy rule (CLAUDE.md): explicit ->where('company_id', …) everywhere —
 * this command never relies on the ambient scope.
 */
class SyncDomainPricing extends Command
{
    use ResolvesDomainCompanies;

    protected $signature = 'domains:sync-pricing
        {--tenant= : Slug ή id εταιρείας (κενό = όλες οι domain-enabled)}
        {--tld= : Μόνο αυτό το TLD (π.χ. gr ή .gr)}';

    protected $description = 'Άντληση κόστους TLD από τους registrars → domain_tld_prices.cost — read-only στον registrar, δεν αγγίζει τιμές πώλησης';

    public function handle(DomainPricingSyncService $sync): int
    {
        $onlyTld = mb_strtolower(ltrim(trim((string) ($this->option('tld') ?? '')), '.'));

        $companies = $this->domainCompanies();
        if ($companies === []) {
            // An EXPLICIT --tenant that resolves to nothing already printed its
            // specific error in the trait — no second (and possibly wrong)
            // message on top, just the failing exit code.
            if ((string) ($this->option('tenant') ?? '') !== '') {
                return self::FAILURE;
            }
            // An EXPLICIT --tld with zero domain-enabled companies is the same
            // typo class — fail loudly instead of a silent no-op.
            if ($onlyTld !== '') {
                $this->error('Καμία εταιρεία με ενεργή διαχείριση domains — δεν έγινε άντληση.');

                return self::FAILURE;
            }
            $this->info('Καμία εταιρεία με ενεργή διαχείριση domains.');

            return self::SUCCESS;
        }

        $failures = 0;
        $matched = 0;
        $syncedTotal = 0;
        foreach ($companies as $company) {
            $synced = 0;
            $updated = 0;
            $created = 0;
            $skipped = 0;
            $tlds = DomainTld::query()
                ->where('company_id', $company->id)
                ->when($onlyTld !== '', fn ($q) => $q->where('tld', $onlyTld))
                ->with('registrarConnection')
                ->orderBy('tld')
                ->get();
            $matched += $tlds->count();
            foreach ($tlds as $tld) {
                if (! $sync->isSyncable($tld)) {
                    $skipped++;

                    continue;
                }
                try {
                    $counts = $sync->sync($tld);
                    $synced++;
                    $updated += $counts['updated'];
                    $created += $counts['created'];
                    // A same-term row in another currency keeps a stale cost —
                    // the operator prices against it, so this is never silent.
                    if ($counts['currency_mismatches'] !== []) {
                        $this->warn("  ⚠ .{$tld->tld}: ο registrar κοστολογεί σε άλλο νόμισμα από υπάρχουσα γραμμή (".implode(', ', $counts['currency_mismatches']).') — ελέγξτε το κόστος της παλιάς γραμμής χειροκίνητα.');
                    }
                } catch (\Throwable $e) {
                    // Count + keep going — one broken TLD must not stall the
                    // whole tenant (same rule as domains:sync).
                    $failures++;
                    $this->warn("  ✗ .{$tld->tld}: ".$e->getMessage());
                }
            }
            $syncedTotal += $synced;

            $this->info("{$company->slug}: {$synced} TLDs, {$updated} κόστη ενημερώθηκαν, {$created} νέες cost-only γραμμές (ανενεργές), {$skipped} skipped (manual/ανενεργή σύνδεση/χωρίς pricing sync), σφάλματα ως τώρα: {$failures}");
        }

        // An EXPLICIT --tld must never no-op silently: unknown TLD = typo,
        // matched-but-all-skipped = the operator asked for a pull that cannot
        // happen (manual route / inactive connection) — both exit FAILURE.
        if ($onlyTld !== '' && $matched === 0) {
            $this->error("Το TLD .{$onlyTld} δεν υπάρχει στον κατάλογο καμίας εταιρείας του run.");

            return self::FAILURE;
        }
        if ($onlyTld !== '' && $syncedTotal === 0 && $failures === 0) {
            $this->error("Το TLD .{$onlyTld} δρομολογείται σε manual/ανενεργή σύνδεση ή χωρίς pricing sync — δεν έγινε άντληση.");

            return self::FAILURE;
        }

        return $failures > 0 ? self::FAILURE : self::SUCCESS;
    }
}
