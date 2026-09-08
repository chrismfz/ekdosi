<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\Domains\DomainCsvImportService;
use Illuminate\Console\Command;

/**
 * domains:import-csv (Πυλώνας A / A2c) — bootstrap a portfolio from a CSV
 * export, primarily the grweb portal export for .gr (the .gr EPP has no
 * list-my-domains command — README §9), but any name+expiry CSV works.
 * Requires an EXPLICIT --tenant: a CSV file belongs to exactly one company.
 * Re-runnable (upserts by fqdn); READ-ONLY everywhere except the domains
 * tables. Rows land ΑΔΕΣΠΟΤΑ — the operator assigns from «Χωρίς πελάτη».
 *
 * Column detection: headers matching «domain/όνομα/name» and «λήξη/expir/
 * renewal/due» are found automatically; --domain-col/--expires-col (header
 * name or 0-based index) override. Delimiter auto-detects , ; or TAB.
 */
class ImportDomainsCsv extends Command
{
    protected $signature = 'domains:import-csv
        {file : Το αρχείο CSV (π.χ. export από grweb)}
        {--tenant= : Slug ή id εταιρείας (ΥΠΟΧΡΕΩΤΙΚΟ — το CSV ανήκει σε μία εταιρεία)}
        {--domain-col= : Στήλη ονόματος (header ή 0-based index) αν δεν ανιχνευθεί}
        {--expires-col= : Στήλη λήξης (header ή 0-based index) αν δεν ανιχνευθεί}
        {--no-header : Το αρχείο ΔΕΝ έχει γραμμή επικεφαλίδων}';

    protected $description = 'Import domains από CSV (grweb export για .gr κ.ά.) → αδέσποτα domains για χειροκίνητη ανάθεση';

    public function handle(DomainCsvImportService $import): int
    {
        $tenant = (string) ($this->option('tenant') ?? '');
        if ($tenant === '') {
            $this->error('Το --tenant είναι υποχρεωτικό — ένα CSV ανήκει σε ΜΙΑ εταιρεία.');

            return self::FAILURE;
        }
        $company = Company::findBySlugOrId($tenant);
        if ($company === null) {
            $this->error("Άγνωστη εταιρεία: {$tenant}");

            return self::FAILURE;
        }
        if (! $company->hasDomainManagement()) {
            $this->error("Η {$company->slug} δεν έχει ενεργή διαχείριση domains.");

            return self::FAILURE;
        }

        $path = (string) $this->argument('file');
        if (! is_file($path) || ! is_readable($path)) {
            $this->error("Το αρχείο δεν βρέθηκε ή δεν διαβάζεται: {$path}");

            return self::FAILURE;
        }

        $rows = $this->parse($path);
        if ($rows === null) {
            return self::FAILURE; // parse() already printed the specific error
        }

        $counts = $import->import($company, $rows, fn (string $m) => $this->warn('  ⚠ '.$m));

        $this->info(
            "{$company->slug}: {$counts['created']} νέα (αδέσποτα), {$counts['updated']} ενημερώσεις λήξης, ".
            "{$counts['unchanged']} αμετάβλητα, {$counts['skipped']} παραλείφθηκαν (tombstones/διαγραμμένα TLD), ".
            "{$counts['invalid']} μη έγκυρες γραμμές."
        );

        // A run where NOT ONE row resolved to an actual domain (created /
        // updated / unchanged / skipped) is a wrong file or column mapping —
        // «invalid» rows alone must not turn the exit green.
        if ($counts['created'] + $counts['updated'] + $counts['unchanged'] + $counts['skipped'] === 0) {
            $this->error('Καμία έγκυρη γραμμή domain — ελέγξτε αρχείο/στήλες (--domain-col/--expires-col).');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * File → structured rows, or null after printing the error.
     *
     * @return ?list<array{fqdn: string, expires_at: ?string}>
     */
    private function parse(string $path): ?array
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            $this->error("Αδυναμία ανάγνωσης: {$path}");

            return null;
        }

        try {
            $first = fgets($handle);
            if ($first === false) {
                $this->error('Κενό αρχείο.');

                return null;
            }
            // Strip a UTF-8 BOM (spreadsheet exports love it) before detection.
            $first = preg_replace('/^\xEF\xBB\xBF/', '', $first) ?? $first;
            $delimiter = $this->detectDelimiter($first);

            $header = str_getcsv(rtrim($first, "\r\n"), $delimiter);
            $hasHeader = ! $this->option('no-header');

            $domainOpt = (string) ($this->option('domain-col') ?? '');
            $expiresOpt = (string) ($this->option('expires-col') ?? '');
            $domainIdx = $this->resolveColumn($domainOpt, $hasHeader ? $header : null, '/domain|όνομα|ονομα|name/iu');
            $expiresIdx = $this->resolveColumn($expiresOpt, $hasHeader ? $header : null, '/λήξη|ληξη|expir|renewal|due/iu');

            // An EXPLICIT column that resolves to nothing (unknown header name
            // or an index past the file's width) is a typo — error out, never
            // silently import the whole file without the column it named.
            foreach ([['--domain-col', $domainOpt, $domainIdx], ['--expires-col', $expiresOpt, $expiresIdx]] as [$flag, $opt, $idx]) {
                if ($opt !== '' && ($idx === null || $idx >= count($header))) {
                    $this->error("Η στήλη {$flag}={$opt} δεν υπάρχει στο αρχείο (βρέθηκαν ".count($header).' στήλες: '.implode(', ', $header).').');

                    return null;
                }
            }

            if ($domainIdx === null && ! $hasHeader) {
                $domainIdx = 0; // headerless: first column is the name unless told otherwise
            }
            if ($domainIdx === null) {
                $this->error('Δεν βρέθηκε στήλη ονόματος — δώστε --domain-col (header ή 0-based index). Headers: '.implode(', ', $header));

                return null;
            }
            if ($expiresIdx === null) {
                $this->warn('Δεν βρέθηκε στήλη λήξης — τα domains θα εισαχθούν χωρίς ημερομηνία λήξης (μη χρεώσιμα μέχρι να οριστεί).');
            }

            $rows = [];
            if (! $hasHeader) {
                $rows[] = $this->row($header, $domainIdx, $expiresIdx);
            }
            while (($line = fgetcsv($handle, 0, $delimiter)) !== false) {
                if ($line === [null]) {
                    continue; // fully blank line
                }
                $rows[] = $this->row($line, $domainIdx, $expiresIdx);
            }

            return $rows;
        } finally {
            fclose($handle);
        }
    }

    /** Pick the candidate delimiter that splits the first line the most. */
    private function detectDelimiter(string $line): string
    {
        $best = ',';
        $bestCount = -1;
        foreach ([',', ';', "\t"] as $candidate) {
            $count = count(str_getcsv($line, $candidate));
            if ($count > $bestCount) {
                $best = $candidate;
                $bestCount = $count;
            }
        }

        return $best;
    }

    /** Explicit option (header name or 0-based index) wins; else header regex. */
    private function resolveColumn(string $option, ?array $header, string $pattern): ?int
    {
        if ($option !== '') {
            if (ctype_digit($option)) {
                return (int) $option;
            }
            foreach ($header ?? [] as $i => $name) {
                if (mb_strtolower(trim((string) $name)) === mb_strtolower(trim($option))) {
                    return $i;
                }
            }

            return null; // an explicit name that matches nothing is «not found»
        }
        foreach ($header ?? [] as $i => $name) {
            if (preg_match($pattern, (string) $name) === 1) {
                return $i;
            }
        }

        return null;
    }

    /** @return array{fqdn: string, expires_at: ?string} */
    private function row(array $line, int $domainIdx, ?int $expiresIdx): array
    {
        return [
            'fqdn' => (string) ($line[$domainIdx] ?? ''),
            'expires_at' => $expiresIdx !== null && isset($line[$expiresIdx]) ? (string) $line[$expiresIdx] : null,
        ];
    }
}
