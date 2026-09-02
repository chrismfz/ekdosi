<?php

namespace App\Services\Portability;

use App\Models\Company;
use App\Models\DeliveryNote;
use App\Models\Invoice;
use App\Models\Scopes\CompanyScope;
use App\Services\Delivery\DeliveryNotePdf;
use App\Services\InvoicePdfRenderer;
use App\Support\Tenancy\CompanyContext;
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
        // actAs is the ROOT fix for the tenant context, and it replaces a narrower
        // attempt that only unscoped the relations THIS class eager-loads. The
        // renderers lazy-load several more of their own — company, paymentMethod,
        // bankAccount, credit notes, delivery-note events and marks — and each of
        // those goes through CompanyScope, so in a super_admin panel action for a
        // company other than the selected tenant the PDFs came out missing IBANs,
        // payment method and related documents. Setting the ambient tenant to the
        // company being exported fixes every one of them, including the ones a
        // future renderer adds.
        //
        // The driving queries below still carry an explicit company_id and drop the
        // scope themselves: which documents are in the archive must not depend on
        // ambient state at all.
        return app(CompanyContext::class)->actAs(
            $company,
            fn (): array => $this->buildArchive($company, $path, $progress),
        );
    }

    /**
     * @param  callable(string):void|null  $progress
     * @return array{invoices:int, delivery_notes:int, failed:int, bytes:int, path:string}
     */
    private function buildArchive(Company $company, string $path, ?callable $progress): array
    {
        if (! is_dir($dir = dirname($path)) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new RuntimeException("Αδυναμία δημιουργίας φακέλου: {$dir}");
        }

        // Temp dir BEFORE the zip: anything failing between open() and the try
        // below would leave an unclosed archive the catch never reaches.
        $tmpDir = storage_path('app/tmp/pdf-archive-'.$company->slug.'-'.uniqid());
        if (! is_dir($tmpDir) && ! mkdir($tmpDir, 0775, true) && ! is_dir($tmpDir)) {
            throw new RuntimeException("Αδυναμία δημιουργίας προσωρινού φακέλου: {$tmpDir}");
        }

        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            @rmdir($tmpDir);

            throw new RuntimeException("Αδυναμία εγγραφής zip: {$path}");
        }

        $counts = ['invoices' => 0, 'delivery_notes' => 0, 'failed' => 0];
        $errors = [];
        $index = [];
        /** Entry names already in the zip — addFile would silently OVERWRITE. */
        $usedEntries = [];

        try {
            $this->addDocuments(
                $zip, $tmpDir, $counts, $errors, $index, $usedEntries, $progress,
                'invoices',
                // withoutGlobalScope + explicit company_id: this runs from a command
                // or a queued job, where the ambient tenant context is a no-op, and
                // from the panel, where it would be the WRONG tenant when a
                // super_admin exports a company other than the selected one.
                Invoice::query()
                    ->withoutGlobalScope(CompanyScope::class)
                    ->where('company_id', $company->getKey())
                    ->with(['customer', 'lines', 'invoiceType'])
                    // chunkById pages by ID, so it must be ORDERED by id: an
                    // orderBy('issued_at') on top made a single backdated row shift
                    // the window and drop documents from the archive without so much
                    // as an errors.txt entry. Chronology is restored when the index
                    // is written, and the per-year folders carry it in the zip.
                    ->orderBy('id'),
                fn (Invoice $doc): string => $this->invoices->render($doc),
            );

            $this->addDocuments(
                $zip, $tmpDir, $counts, $errors, $index, $usedEntries, $progress,
                'delivery_notes',
                DeliveryNote::query()
                    ->withoutGlobalScope(CompanyScope::class)
                    ->where('company_id', $company->getKey())
                    ->with(['customer', 'lines', 'deliveryType'])
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
            // Close, then DELETE. Closing alone left a readable, complete-looking
            // zip at the operator's output path with no index.csv and no
            // errors.txt — precisely the archive that gets handed over as final.
            // A failed export must leave nothing to mistake for a good one.
            @$zip->close();
            @unlink($path);
            $this->cleanUp($tmpDir);

            throw $e;
        }

        $this->cleanUp($tmpDir);

        return $counts + [
            'bytes' => is_file($path) ? (filesize($path) ?: 0) : 0,
            'path' => $path,
        ];
    }

    /**
     * @param  array<string, int>  $counts
     * @param  array<int, string>  $errors
     * @param  array<int, array<int, string>>  $index
     * @param  array<string, true>  $usedEntries
     * @param  callable(string):void|null  $progress
     */
    private function addDocuments(
        ZipArchive $zip,
        string $tmpDir,
        array &$counts,
        array &$errors,
        array &$index,
        array &$usedEntries,
        ?callable $progress,
        string $bucket,
        $query,
        callable $render,
    ): void {
        $folder = $bucket === 'invoices' ? 'παραστατικά' : 'δελτία-αποστολής';

        $query->chunkById(self::CHUNK, function ($documents) use (
            $zip, $tmpDir, &$counts, &$errors, &$index, &$usedEntries, $progress, $bucket, $folder, $render
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

                // addFile, not addFromString: ZipArchive reads the path at close(),
                // so only one PDF is ever in memory.
                //
                // The entry name carries the document id when it would otherwise
                // collide: addFile defaults to FL_OVERWRITE, so two documents whose
                // safeName() output matches — same invcode in the same year after
                // the character strip, or two rows with no invcode at all —
                // collapsed into ONE zip entry while index.csv and the counts still
                // claimed two. Same silent-loss class as the paging bug.
                $entry = "{$folder}/{$year}/".$this->safeName($label).'.pdf';

                if (isset($usedEntries[$entry])) {
                    $entry = "{$folder}/{$year}/".$this->safeName($label).'-'.$document->getKey().'.pdf';
                }

                $usedEntries[$entry] = true;

                if ($zip->addFile($file, $entry) !== true) {
                    throw new RuntimeException(
                        "Αδυναμία προσθήκης του «{$label}» στο αρχείο — η εξαγωγή διακόπηκε."
                    );
                }

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

    /**
     * Empty and remove the temp directory, whatever ended up in it.
     *
     * Deliberately scans the directory rather than replaying a list of files we
     * remember writing: rmdir fails on a non-empty directory, so ANY stray entry —
     * a partial file from a failed write, something a concurrent run left — leaked
     * the whole directory under storage/app/tmp on every failed export. Tracking
     * paths and hoping the list is complete is how that happened; this cannot miss.
     */
    private function cleanUp(string $tmpDir): void
    {
        if (! is_dir($tmpDir)) {
            return;
        }

        foreach (glob($tmpDir.'/*') ?: [] as $entry) {
            is_dir($entry) ? @rmdir($entry) : @unlink($entry);
        }

        @rmdir($tmpDir);
    }
}
