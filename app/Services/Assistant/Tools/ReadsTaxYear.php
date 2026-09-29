<?php

namespace App\Services\Assistant\Tools;

use App\Models\Company;

/**
 * Shared input handling of the «Φορολογικά» tools (tax_overview, e3_snapshot,
 * data_freshness): an optional calendar `year` (default the running one) and the
 * Greek-tenants-only gate the page itself applies (Greek company tax + ΦΠΑ + Ε3 —
 * meaningless for the Estonian tenant).
 */
trait ReadsTaxYear
{
    /** @return int|array{error: string} the year, or a structured error */
    private function year(array $input): int|array
    {
        $now = (int) now()->year;
        $year = isset($input['year']) && $input['year'] !== '' ? (int) $input['year'] : $now;

        return $year < 2019 || $year > $now
            ? ['error' => "Μη έγκυρο έτος {$year} (2019–{$now})."]
            : $year;
    }

    /** @return array{error: string}|null */
    private function rejectNonGreek(Company $tenant): ?array
    {
        return $tenant->country_code === 'GR'
            ? null
            : ['error' => 'Τα «Φορολογικά» (φόρος εισοδήματος / ΦΠΑ / Ε3) αφορούν μόνο ελληνικές εταιρείες.'];
    }
}
