<?php

namespace App\Console\Commands;

use App\Services\Taric\CnCatalog;
use Illuminate\Console\Command;
use Throwable;

/**
 * Refresh the Συνδυασμένη Ονοματολογία reference list (`cn_codes`) for a year — the CLI twin
 * of «Κωδικοί ΣΟ / TARIC» → «Ενημέρωση από ΕΕ». Downloads the EU's official SKOS/RDF
 * (data.europa.eu «combined-nomenclature-{year}») or parses a local file (--file).
 *
 * Usage:
 *   php artisan cn:import --year=2027
 *   php artisan cn:import --year=2026 --file=/path/ESTAT-CN2026.rdf
 */
class CnImport extends Command
{
    protected $signature = 'cn:import
        {--year= : CN year (default: the current year)}
        {--file= : Parse a local SKOS/RDF file instead of downloading}';

    protected $description = 'Import the EU Combined Nomenclature (Greek labels) for a year into cn_codes (TARIC reference list).';

    public function handle(CnCatalog $catalog): int
    {
        $year = (int) ($this->option('year') ?: now()->year);
        if ($year < 2020 || $year > now()->year + 1) {
            $this->error('Μη έγκυρο έτος.');

            return self::FAILURE;
        }

        try {
            $count = $this->option('file')
                ? $catalog->importRdf((string) $this->option('file'), $year)
                : $catalog->importFromEu($year)['count'];
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("ΣΟ {$year}: {$count} κωδικοί.");

        return self::SUCCESS;
    }
}
