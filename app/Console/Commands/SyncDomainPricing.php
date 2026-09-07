<?php

namespace App\Console\Commands;

use App\Models\Company;
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
    protected $signature = 'domains:sync-pricing
        {--tenant= : Slug ή id εταιρείας (κενό = όλες οι domain-enabled)}
        {--tld= : Μόνο αυτό το TLD (π.χ. gr ή .gr)}';

    protected $description = 'Άντληση κόστους TLD από τους registrars → domain_tld_prices.cost — read-only στον registrar, δεν αγγίζει τιμές πώλησης';

    public function handle(DomainPricingSyncService $sync): int
    {
        $companies = $this->companies();
        if ($companies === []) {
            // An EXPLICIT --tenant that resolves to nothing is an error (typo,
            // or the pillar is off) — same loud-failure rule as domains:sync.
            if ((string) ($this->option('tenant') ?? '') !== '') {
                return self::FAILURE;
            }
            $this->info('Καμία εταιρεία με ενεργή διαχείριση domains.');

            return self::SUCCESS;
        }

        $onlyTld = mb_strtolower(ltrim(trim((string) ($this->option('tld') ?? '')), '.'));
        $failures = 0;
        $matched = 0;
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
                } catch (\Throwable $e) {
                    // Count + keep going — one broken TLD must not stall the
                    // whole tenant (same rule as domains:sync).
                    $failures++;
                    $this->warn("  ✗ .{$tld->tld}: ".$e->getMessage());
                }
            }

            $this->info("{$company->slug}: {$synced} TLDs, {$updated} κόστη ενημερώθηκαν, {$created} νέες cost-only γραμμές (ανενεργές), {$skipped} skipped (manual/ανενεργή σύνδεση/χωρίς pricing sync), σφάλματα ως τώρα: {$failures}");
        }

        // An EXPLICIT --tld that matched nothing anywhere is the same typo
        // class as a bad --tenant — fail loudly instead of a silent no-op.
        if ($onlyTld !== '' && $matched === 0) {
            $this->error("Το TLD .{$onlyTld} δεν υπάρχει στον κατάλογο καμίας εταιρείας του run.");

            return self::FAILURE;
        }

        return $failures > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** @return list<Company> */
    private function companies(): array
    {
        $tenant = (string) ($this->option('tenant') ?? '');
        if ($tenant !== '') {
            $company = Company::findBySlugOrId($tenant);
            if ($company === null) {
                $this->error("Άγνωστη εταιρεία: {$tenant}");

                return [];
            }
            if (! $company->hasDomainManagement()) {
                $this->error("Η {$company->slug} δεν έχει ενεργή διαχείριση domains.");

                return [];
            }

            return [$company];
        }

        return Company::query()->where('enable_domain_management', true)->orderBy('id')->get()->all();
    }
}
