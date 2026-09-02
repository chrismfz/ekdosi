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
    /** Render + zip in batches so the temp directory never holds everything. */
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
                    ->with(['customer', 'lines', 'invoiceType'])
                    ->orderBy('issued_at')
                    ->orderBy('id'),
                fn (Invoice $doc): string => $this->invoices->render($doc),
            );

            $this->addDocuments(
                $zip, $tmpDir, $tmpFiles, $counts, $errors, $index, $progress,
                'delivery_notes',
                DeliveryNote::query()
                    ->withoutGlobalScope(CompanyScope::class)
                    ->where('company_id', $company->getKey())
                    ->with(['customer', 'lines', 'deliveryType'])
                    ->orderBy('issued_at')
                    ->orderBy('id'),
                fn (DeliveryNote $doc): string => $this->deliveryNotes->render($doc),
            );

            $zip->addFromString('index.csv', $this->indexCsv($index));
            $zip->addFromString('README.txt', $this->readme($company, $counts));

            if ($errors !== []) {
                $zip->addFromString('errors.txt', implode("\n", $errors)."\n");
            }

            $zip->close();
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
                file_put_contents($file, $bytes);
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
        $out = fopen('php://temp', 'r+');

        // BOM so Excel opens the Greek columns correctly on a double-click — the
        // whole point of this archive is that it works without this application.
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Είδος', 'Κωδικός', 'Ημερομηνία', 'Πελάτης', 'Σύνολο', 'ΜΑΡΚ', 'Κατάσταση myDATA']);

        foreach ($index as $row) {
            fputcsv($out, $row);
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
