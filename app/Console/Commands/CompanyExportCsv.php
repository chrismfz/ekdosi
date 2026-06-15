<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\Portability\CsvEntityExporter;
use Illuminate\Console\Command;

/**
 * Portability Phase 3 — selective per-entity CSV export.
 *
 *   php artisan company:export-csv --tenant=myip                 # all available
 *   php artisan company:export-csv --tenant=myip --only=customers,products
 *   php artisan company:export-csv --tenant=myip --list          # show entity keys
 *
 * Plain CSVs (one per chosen entity), zipped — human-usable, NOT a restore
 * bundle (that's company:export). Tenant-scoped; secret columns redacted.
 */
class CompanyExportCsv extends Command
{
    protected $signature = 'company:export-csv
        {--tenant= : Company slug}
        {--only= : Comma-separated entity keys (default: all available)}
        {--out= : Output .zip path (default: storage/app/exports/…)}
        {--list : List the available entity keys and exit}';

    protected $description = 'Export selected tenant entities (customers/products/invoices…) to per-entity CSVs in a .zip';

    public function handle(CsvEntityExporter $exporter): int
    {
        if ($this->option('list')) {
            foreach ($exporter->available() as $key) {
                $this->line(sprintf('  %-20s %s', $key, CsvEntityExporter::LABELS[$key] ?? ''));
            }

            return self::SUCCESS;
        }

        $slug = (string) $this->option('tenant');
        if ($slug === '') {
            $this->error('Δώσε --tenant=SLUG (ή --list για τα διαθέσιμα entities).');

            return self::FAILURE;
        }

        $company = Company::query()->where('slug', $slug)->first();
        if ($company === null) {
            $this->error("Δεν βρέθηκε εταιρία με slug «{$slug}».");

            return self::FAILURE;
        }

        $available = $exporter->available();
        $only = trim((string) $this->option('only'));
        if ($only === '') {
            $entities = $available;
        } else {
            $requested = array_values(array_filter(array_map('trim', explode(',', $only))));
            $entities = array_values(array_intersect($requested, $available));
            $unknown = array_diff($requested, $available);
            if ($unknown !== []) {
                $this->warn('Άγνωστα/μη διαθέσιμα entities (παραλείπονται): '.implode(', ', $unknown));
            }
        }

        if ($entities === []) {
            $this->error('Κανένα έγκυρο entity για εξαγωγή. Δες «--list».');

            return self::FAILURE;
        }

        $files = $exporter->export($company, $entities);

        $out = (string) ($this->option('out')
            ?: storage_path('app/exports/'.$slug.'-csv-'.now()->format('Ymd-His').'.zip'));
        $exporter->writeZip($files, $out);

        $this->info("Εξήχθη: {$out}");
        foreach ($files as $name => $csv) {
            // header line + BOM are 1 line; rows = total lines − 1.
            $rows = max(0, substr_count($csv, "\n") - 1);
            $this->line(sprintf('  %-24s %d εγγραφές', $name, $rows));
        }

        return self::SUCCESS;
    }
}
