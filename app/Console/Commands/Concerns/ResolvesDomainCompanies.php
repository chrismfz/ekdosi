<?php

namespace App\Console\Commands\Concerns;

use App\Models\Company;

/**
 * Shared --tenant resolution for the domains:* commands (sync, sync-pricing,
 * the A2c import) — ONE place for the gating rules: an explicit tenant that
 * doesn't exist or has the pillar off errors out (returns []); no tenant =
 * every domain-enabled company. Extracted so a future gating change can't
 * drift between copies.
 */
trait ResolvesDomainCompanies
{
    /** @return list<Company> */
    private function domainCompanies(): array
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
