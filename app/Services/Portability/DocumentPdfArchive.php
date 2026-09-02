<?php

namespace App\Services\Portability;

use App\Models\Company;
use App\Models\DeliveryNote;
use App\Models\Invoice;
use App\Models\Scopes\CompanyScope;
use App\Services\Delivery\DeliveryNotePdf;
use App\Services\InvoicePdfRenderer;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Every one of a tenant's documents, rendered to PDF, in one zip (MYD-025).
 *
 * The export bundle (`CompanyExporter`) is a RESTORE artifact: it only means
 * something to another ekdosi. A tenant that is leaving for good — moved to its
 * own host, moved to another vendor, wound up — needs its παραστατικά in the form
 * a human and an accountant can read, because it will not have this system any
 * more. That is what makes deletion an acceptable operation: the operator can
 * hand over the documents, not just a database dump.
 *
 * Both are needed and neither replaces the other, so this is deliberately a
 * SEPARATE artifact rather than a folder inside the bundle: one is for machines
 * and is encrypted, the other is for people and must open with a double-click.
 *
 * ── Memory ─────────────────────────────────────────────────────────────────
 * A tenant can hold tens of thousands of documents, so nothing is accumulated:
 * each PDF is rendered, written to a temp file, and handed to the zip by PATH.
 * ZipArchive reads those files at close(), so peak memory is one PDF, not the
 * whole archive — `addFromString` would have held every byte until close.
 *
 * ── Failure policy ─────────────────────────────────────────────────────────
 * One unrenderable document must not cost the operator the other 9,999. Failures
 * are collected, listed in `errors.txt` inside the zip, and reported in the
 * result; the run continues. An export that silently dropped documents would be
 * worse than one that says which are missing.
 */
class DocumentPdfArchive
{
    /**
     * Documents pulled per query. NOT a bound on the temp directory: ZipArchive
     * reads the files at close(), so every rendered PDF must still exist then —
     * peak DISK is the whole archive, peak MEMORY is one PDF.
     */
    private const CHUNK = 100;

    public function __construct(
        private readonly InvoicePdfRenderer $invoices,
        private readonly DeliveryNotePdf $deliveryNotes,
    ) {}

    /**
     * @param  callable(string):void|null  $progress  called with a human line per batch
     * @return array{invoices:int, delivery_notes:int, failed:int, bytes:int, path:string}
     */
    public function build(Company $company, string $path, ?callable $progress = null): array
    {
        if (! is_dir($dir = dirname($path)) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new RuntimeException("Αδυναμία δημιουργίας φακέλου: {$dir}");
        }

        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Αδυναμία εγγραφής zip: {$path}");
        }

        $tmpDir = storage_path('app/tmp/pdf-archive-'.$company->slug.'-'.uniqid());
        if (! is_dir($tmpDir) && ! mkdir($tmpDir, 0775, true) && ! is_dir($tmpDir)) {
            throw new RuntimeException("Αδυναμία δημιουργίας προσωρινού φακέλου: {$tmpDir}");
        }

        $counts = ['invoices' => 0, 'delivery_notes' => 0, 'failed' => 0];
        $errors = [];
        $index = [];
        $tmpFiles = [];

        try {
            $this->addDocuments(
                $zip, $tmpDir, $tmpFiles, $counts, $errors, $index, $progress,
                'invoices',
                // withoutGlobalScope + explicit company_id: this runs from a command
                // or a queued job, where the ambient tenant context is a no-op, and
                // from the panel, where it would be the WRONG tenant when a
                // super_admin exports a company other than the selected one.
                Invoice::query()
                    ->withoutGlobalScope(CompanyScope::class)
                    ->where('company_id', $company->getKey())
                    // The EAGER LOADS need the same escape: dropping the scope on
                    // the outer query only means that inside a panel action for
                    // another tenant every document renders with no lines, no
                    // customer and no type — a zip full of blank PDFs, silently.
                    ->with(self::unscoped(['customer', 'lines', 'invoiceType']))
                    // chunkById pages by ID, so it must be ORDERED by id: an
                    // orderBy('issued_at') on top made a single backdated row shift
                    // the window and drop documents from the archive without so much
                    // as an errors.txt entry. Chronology is restored when the index
                    // is written, and the per-year folders carry it in the zip.
                    ->orderBy('id'),
                fn (Invoice $doc): string => $this->invoices->render($doc),
            );

            $this->addDocuments(
                $zip, $tmpDir, $tmpFiles, $counts, $errors, $index, $progress,
                'delivery_notes',
                DeliveryNote::query()
                    ->withoutGlobalScope(CompanyScope::class)
                    ->where('company_id', $company->getKey())
                    ->with(self::unscoped(['customer', 'lines', 'deliveryType']))
                    ->orderBy('id'),
                fn (DeliveryNote $doc): string => $this->deliveryNotes->render($doc),
            );

            $zip->addFromString('index.csv', $this->indexCsv($index));
            $zip->addFromString('README.txt', $this->readme($company, $counts));

            if ($errors !== []) {
                $zip->addFromString('errors.txt', implode("\n", $errors)."\n");
            }

            // close() is where ZipArchive actually reads every temp file and writes
            // the archive, so it is where a full disk surfaces. Unchecked, it left a
            // truncated zip the operator would have handed over as complete.
            if ($zip->close() !== true) {
                throw new RuntimeException(
                    "Αδυναμία ολοκλήρωσης του αρχείου {$path} — πιθανώς δεν υπάρχει χώρος στον δίσκο."
                );
            }
        } catch (Throwable $e) {
            // A ZipArchive left open holds a partial file that looks valid; close
            // it so the failure is visible as a missing/short archive rather than
            // a silently truncated one the operator would hand over.
            @$zip->close();
            $this->cleanUp($tmpFiles, $tmpDir);

            throw $e;
        }

        $this->cleanUp($tmpFiles, $tmpDir);

        return $counts + [
            'bytes' => is_file($path) ? (filesize($path) ?: 0) : 0,
            'path' => $path,
        ];
    }

    /**
     * Eager loads that also drop CompanyScope.
     *
     * `with(['lines'])` builds its own query, which the scope filters by the
     * AMBIENT tenant — null on the CLI (harmless) but the WRONG tenant in a
     * super_admin panel action, where it silently returns nothing.
     *
     * @param  array<int, string>  $relations
     * @return array<string, callable>
     */
    private static function unscoped(array $relations): array
    {
        $out = [];

        foreach ($relations as $relation) {
            $out[$relation] = fn ($query) => $query->withoutGlobalScope(CompanyScope::class);
        }

        return $out;
    }

    /**
     * @param  array<int, string>  $tmpFiles
     * @param  array<string, int>  $counts
     * @param  array<int, string>  $errors
     * @param  array<int, array<int, string>>  $index
     * @param  callable(string):void|null  $progress
     */
    private function addDocuments(
        ZipArchive $zip,
        string $tmpDir,
        array &$tmpFiles,
        array &$counts,
        array &$errors,
        array &$index,
        ?callable $progress,
        string $bucket,
        $query,
        callable $render,
    ): void {
        $folder = $bucket === 'invoices' ? 'παραστατικά' : 'δελτία-αποστολής';

        $query->chunkById(self::CHUNK, function ($documents) use (
            $zip, $tmpDir, &$tmpFiles, &$counts, &$errors, &$index, $progress, $bucket, $folder, $render
        ): void {
            foreach ($documents as $document) {
                $label = (string) ($document->invcode ?: 'χωρίς-κωδικό-'.$document->getKey());

                try {
                    $bytes = $render($document);
                } catch (Throwable $e) {
                    $counts['failed']++;
                    $errors[] = "{$label}: {$e->getMessage()}";
                    Log::warning('PDF archive: document failed to render', [
                        'company_id' => $document->company_id,
                        'document' => $label,
                        'error' => $e->getMessage(),
                    ]);

                    continue;
                }

                $year = $document->issued_at?->format('Y') ?? 'χωρίς-ημερομηνία';
                $file = $tmpDir.'/'.$bucket.'-'.$document->getKey().'.pdf';

                // Checked: a full disk makes file_put_contents return false, and an
                // unchecked write reported «0 failed» over an archive that was
                // quietly missing documents — the exact outcome this class exists
                // to prevent. Failing the whole run is right here: a disk that
                // cannot hold PDF 400 will not hold PDF 401 either.
                if (@file_put_contents($file, $bytes) === false) {
                    throw new RuntimeException(
                        "Αδυναμία εγγραφής προσωρινού PDF για «{$label}» — έλεγξε τον χώρο στον δίσκο."
                    );
                }

                $tmpFiles[] = $file;

                // addFile, not addFromString: ZipArchive reads the path at close(),
                // so only one PDF is ever in memory.
                $zip->addFile($file, "{$folder}/{$year}/".$this->safeName($label).'.pdf');

                $counts[$bucket]++;
                $index[] = [
                    $folder,
                    $label,
                    $document->issued_at?->format('Y-m-d') ?? '',
                    (string) ($document->customer?->name ?? $document->company_name ?? ''),
                    $bucket === 'invoices' ? (string) $document->gross_total : '',
                    (string) ($document->mydata_mark ?? ''),
                    (string) ($document->mydata_state ?? ''),
                ];
            }

            if ($progress !== null) {
                $progress("  {$folder}: {$counts[$bucket]}");
            }
        });
    }

    /** @param array<int, array<int, string>> $index */
    private function indexCsv(array $index): string
    {
        // Chronological: chunkById had to page by id, so order is restored here.
        usort($index, static fn (array $a, array $b): int => [$a[2], $a[1]] <=> [$b[2], $b[1]]);

        $out = fopen('php://temp', 'r+');

        // BOM so Excel opens the Greek columns correctly on a double-click — the
        // whole point of this archive is that it works without this application.
        fwrite($out, "\xEF\xBB\xBF");

        // Explicit args, as CsvEntityExporter does: silences the PHP 8.4 fputcsv
        // deprecation and drops the legacy backslash escaping.
        fputcsv($out, ['Είδος', 'Κωδικός', 'Ημερομηνία', 'Πελάτης', 'Σύνολο', 'ΜΑΡΚ', 'Κατάσταση myDATA'], ',', '"', '');

        foreach ($index as $row) {
            fputcsv($out, array_map(self::neutraliseFormula(...), $row), ',', '"', '');
        }

        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        return $csv;
    }

    /** @param array<string, int> $counts */
    private function readme(Company $company, array $counts): string
    {
        return implode("\n", [
            'Αρχείο παραστατικών — '.$company->name.' (ΑΦΜ '.($company->afm ?: '—').')',
            'Δημιουργήθηκε: '.now()->format('d/m/Y H:i'),
            '',
            'Περιεχόμενα:',
            '  παραστατικά/<έτος>/<κωδικός>.pdf        — '.$counts['invoices'].' τιμολόγια/αποδείξεις',
            '  δελτία-αποστολής/<έτος>/<κωδικός>.pdf   — '.$counts['delivery_notes'].' δελτία',
            '  index.csv                                — κατάλογος όλων, με ΜΑΡΚ',
            $counts['failed'] > 0
                ? '  errors.txt                               — '.$counts['failed'].' έγγραφα ΔΕΝ παρήχθησαν'
                : '',
            '',
            'Τα ΜΑΡΚ είναι οι αριθμοί καταχώρησης στην ΑΑΔΕ. Τα παραστατικά παραμένουν',
            'καταχωρημένα εκεί ανεξάρτητα από αυτό το αρχείο.',
        ]);
    }

    /**
     * CSV formula-injection guard — the same rule as CsvEntityExporter and
     * LedgerBookExporter. A cell starting with = + - @ TAB CR is executed by
     * Excel/LibreOffice, and customer names here are untrusted (operator- and
     * WHMCS-sourced). Numbers are left alone so totals stay analysable.
     */
    private static function neutraliseFormula(string $s): string
    {
        if ($s === '' || is_numeric($s)) {
            return $s;
        }

        return in_array($s[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$s : $s;
    }

    /** Keep the Greek, drop what a filesystem or zip reader would choke on. */
    private function safeName(string $name): string
    {
        $safe = preg_replace('#[/\\\\:*?"<>|]+#u', '-', $name) ?? $name;

        return trim($safe) !== '' ? trim($safe) : 'έγγραφο';
    }

    /** @param array<int, string> $tmpFiles */
    private function cleanUp(array $tmpFiles, string $tmpDir): void
    {
        foreach ($tmpFiles as $file) {
            @unlink($file);
        }

        @rmdir($tmpDir);
    }
}
