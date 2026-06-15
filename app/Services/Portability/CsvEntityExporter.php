<?php

namespace App\Services\Portability;

use App\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use ZipArchive;

/**
 * Portability Phase 3 — selective per-entity CSV export. Distinct from the
 * full restore BUNDLE (`CompanyExporter`, a sealed .zip for re-import): this
 * gives the operator plain, human-usable CSVs of CHOSEN entities (open in
 * Excel, hand to an accountant, feed another system). Tenant-scoped; the same
 * secret columns the bundle redacts are redacted here too.
 */
class CsvEntityExporter
{
    /**
     * Exportable entities (table => Greek label), in UI/catalogue order. A
     * curated, human-meaningful subset of the company-scoped tables — not every
     * internal table (the bundle is for a full carry-over; this is «δώσε μου τα X»).
     *
     * @var array<string, string>
     */
    public const LABELS = [
        'customers' => 'Πελάτες',
        'customer_contacts' => 'Επαφές πελατών',
        'suppliers' => 'Προμηθευτές',
        'products' => 'Προϊόντα / Υπηρεσίες',
        'invoices' => 'Παραστατικά (κεφαλίδες)',
        'invoice_lines' => 'Γραμμές παραστατικών',
        'payments' => 'Πληρωμές',
        'quotes' => 'Προσφορές',
        'quote_lines' => 'Γραμμές προσφορών',
        'expenses' => 'Έξοδα',
        'expense_lines' => 'Γραμμές εξόδων',
        'invoice_types' => 'Τύποι παραστατικών',
        'vat_categories' => 'Κατηγορίες ΦΠΑ',
        'payment_methods' => 'Τρόποι πληρωμής',
        'bank_accounts' => 'Τραπεζικοί λογαριασμοί',
        'product_categories' => 'Κατηγορίες προϊόντων',
    ];

    /**
     * Entity keys that actually exist in this DB, in catalogue order.
     *
     * @return list<string>
     */
    public function available(): array
    {
        return array_values(array_filter(
            array_keys(self::LABELS),
            static fn (string $t): bool => Schema::hasTable($t),
        ));
    }

    /**
     * Build a CSV per selected entity for the tenant. Unknown / non-existent
     * entities are skipped (never throws on a stale key). Secret columns are
     * redacted — an export must never carry server creds.
     *
     * @param  list<string>  $entities
     * @return array<string, string> filename ("customers.csv") => CSV content
     */
    public function export(Company $company, array $entities): array
    {
        $out = [];
        foreach ($entities as $table) {
            if (! isset(self::LABELS[$table]) || ! Schema::hasTable($table)) {
                continue;
            }
            $out[$table.'.csv'] = $this->tableToCsv((int) $company->getKey(), $table);
        }

        return $out;
    }

    /**
     * Write a set of {filename => content} CSVs into a .zip on disk.
     *
     * @param  array<string, string>  $files
     */
    public function writeZip(array $files, string $path): void
    {
        File::ensureDirectoryExists(dirname($path));

        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Δεν ήταν δυνατή η δημιουργία του .zip στο '.$path);
        }
        foreach ($files as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();
    }

    /** One tenant-scoped table → a CSV string (UTF-8 BOM so Excel reads Greek). */
    private function tableToCsv(int $companyId, string $table): string
    {
        $columns = Schema::getColumnListing($table);
        $redact = CompanyExporter::REDACTED_COLUMNS[$table] ?? [];

        $fh = fopen('php://temp', 'r+');
        fwrite($fh, "\xEF\xBB\xBF");   // BOM
        // Explicit args: silences the PHP 8.4 fputcsv deprecation AND drops the
        // proprietary backslash escaping for RFC-4180 quote-doubling.
        fputcsv($fh, $columns, ',', '"', '');

        DB::table($table)
            ->where('company_id', $companyId)
            ->orderBy('id')
            ->chunk(1000, function ($rows) use ($fh, $columns, $redact): void {
                foreach ($rows as $row) {
                    $arr = (array) $row;
                    $line = [];
                    foreach ($columns as $col) {
                        $value = in_array($col, $redact, true) ? null : ($arr[$col] ?? null);
                        $line[] = self::scalar($value);
                    }
                    fputcsv($fh, $line, ',', '"', '');
                }
            });

        rewind($fh);
        $csv = (string) stream_get_contents($fh);
        fclose($fh);

        return $csv;
    }

    private static function scalar(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return (string) $value;
    }
}
