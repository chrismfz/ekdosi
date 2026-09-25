<?php

namespace App\Console\Commands\Concerns;

use App\Models\Company;

/**
 * `--tenant=SLUG|ID` + repeatable `--year=YYYY` for the myDATA back-fill commands
 * (mydata:import-expenses, mydata:e3-snapshot). Each returns null after printing
 * the error, so the caller just `return self::FAILURE`.
 */
trait ParsesTenantAndYears
{
    protected function tenantOption(): ?Company
    {
        $key = (string) $this->option('tenant');
        // Slug first, then a numeric id — never an OR that could match two tenants.
        $tenant = $key === '' ? null
            : (Company::query()->where('slug', $key)->first()
                ?? (ctype_digit($key) ? Company::query()->find((int) $key) : null));

        if (! $tenant) {
            $this->error('Δώστε υπαρκτή εταιρία: --tenant=SLUG');
        }

        return $tenant;
    }

    /** @return list<int>|null sorted, distinct */
    protected function yearsOption(): ?array
    {
        $raw = (array) $this->option('year');
        // Reject anything but a plain year: intval('2023,2024') would silently keep only 2023.
        if (array_filter($raw, fn ($y) => ! ctype_digit((string) $y)) !== []) {
            $this->error('Κάθε --year πρέπει να είναι ένα έτος (π.χ. --year=2023 --year=2024).');

            return null;
        }

        $years = array_values(array_unique(array_map('intval', $raw)));
        sort($years);
        $thisYear = (int) now()->year;
        if ($years === [] || array_filter($years, fn (int $y) => $y < 2019 || $y > $thisYear) !== []) {
            $this->error("Δώστε έτος/έτη με --year (2019–{$thisYear}).");

            return null;
        }

        return $years;
    }
}
