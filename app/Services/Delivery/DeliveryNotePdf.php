<?php

namespace App\Services\Delivery;

use App\Models\Company;
use App\Models\DeliveryNote;
use App\Support\MyData\QrImage;
use App\Support\Pdf\PdfLabels;
use Barryvdh\DomPDF\Facade\Pdf;
use Dompdf\Dompdf;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\FilesystemException;

/**
 * Renders a Δελτίο Αποστολής (myDATA Παραστατικό Διακίνησης, 9.x) to PDF bytes
 * via DomPDF + the Blade in resources/views/delivery-notes/pdf.blade.php.
 *
 * Value-LESS twin of App\Services\InvoicePdfRenderer (mirrors its shape: the
 * same DomPDF facade, the same A4 portrait paper, the same QR data-URI helper
 * (App\Support\MyData\QrImage) and the same per-render ini guard). What differs:
 *   - NO prices / VAT / totals — a delivery note carries quantity only.
 *   - The footer QR + MARK only render when the note is FILED
 *     (mydata_state === 'VALID'); a draft shows the «ΠΡΟΧΕΙΡΟ» marker, no QR.
 *
 * Self-contained: does NOT touch the invoice renderer, the submitter, or the
 * form. The Greek labels for σκοπός διακίνησης / τρόπος μεταφοράς / μονάδα
 * μέτρησης are resolved in the Blade via App\Support\MyData\DeliveryCodes.
 */
class DeliveryNotePdf
{
    /** Same per-render guard rationale as InvoicePdfRenderer. */
    private const RENDER_MEMORY_LIMIT = '512M';

    private const RENDER_TIME_LIMIT_SECONDS = 60;

    public function render(DeliveryNote $note): string
    {
        $note->loadMissing(['lines', 'deliveryType', 'customer', 'company']);

        // QR only when filed (VALID) AND we actually have the AADE qrUrl. A
        // draft note has no mydata_url, so this naturally stays null for it.
        $qrDataUri = ($note->mydata_state === 'VALID' && $note->mydata_url)
            ? $this->renderQrDataUri($note->mydata_url)
            : null;

        $logoDataUri = $this->loadLogoDataUri($note->company);

        // i18n: a ΔΑ is a legal document, so once FILED its language is frozen on the
        // note — resolved from the snapshotted recipient country (GR/internal → Greek,
        // foreign → bilingual), the same rule as the invoice. recipientCountryIso()
        // falls back to the linked customer's country only for an UNFILED draft (no
        // snapshot yet — nothing is frozen); a filed note reads the snapshot alone.
        $L = PdfLabels::for(PdfLabels::resolveLanguage(null, $note->recipientCountryIso()));

        $previousMemory = ini_get('memory_limit');
        $previousTime = ini_get('max_execution_time');

        try {
            @ini_set('memory_limit', self::RENDER_MEMORY_LIMIT);
            @set_time_limit(self::RENDER_TIME_LIMIT_SECONDS);

            $pdf = Pdf::loadView('delivery-notes.pdf', [
                'note' => $note,
                'tenant' => $note->company,
                'qrDataUri' => $qrDataUri,
                'logoDataUri' => $logoDataUri,
                'L' => $L,
                // Two audit trails, printed when present: the movement lifecycle
                // (carrier/recipient events; events() already orders oldest→newest)
                // + the myDATA SUBMISSION marks only. delivery_marks also stores
                // lifecycle (REGISTER_TRANSFER/CONFIRM_OUTCOME → shown in «Διακίνηση»)
                // and failed attempts (PROVIDER_FAILED/REJECTED, no MARK) — exclude
                // both so «Υποβολές myDATA» isn't duplicated/noisy. STATE_SYNC (MYD-019,
                // a remote-cancel detected via status refresh) is ALSO excluded: it is
                // not a submission and carries the issue MARK, so it would read as a
                // duplicate submission of the same MARK — the cancelled banner conveys
                // the terminal state to a PDF reader, and the «Ιστορικό myDATA» panel
                // tab carries the full STATE_SYNC forensic row.
                'events' => $note->events()->get(),
                'marks' => $note->marks()
                    ->whereIn('mydata_action', ['INSERT', 'PROVIDER_INSERT', 'CANCEL'])
                    ->oldest()->get(),
            ])
                ->setPaper('A4', 'portrait');

            // «Σελίδα X από Y» drawn on the DomPDF canvas after layout (counter(pages)
            // resolves to 0 inside a fixed footer on DomPDF 3.x). Done here — NOT via an
            // in-template `<script type="text/php">` — so isPhpEnabled stays OFF on a
            // render that carries recipient data. Same approach as InvoicePdfRenderer.
            $dompdf = $pdf->getDomPDF();
            $dompdf->render();
            $this->drawPager($dompdf, $L);

            return $dompdf->output();
        } finally {
            @ini_set('memory_limit', $previousMemory);
            @set_time_limit((int) $previousTime);
        }
    }

    /**
     * «Σελίδα X από Y», centred at the page bottom on every page, via the DomPDF
     * canvas (mirrors InvoicePdfRenderer::drawPager). Localized via the note's frozen
     * $labels. Kept ~6.5mm above the sheet edge to clear a typical printer's
     * non-printable margin; colour matches the footer text (#6b7280).
     */
    private function drawPager(Dompdf $dompdf, callable $labels): void
    {
        $canvas = $dompdf->getCanvas();
        $fontMetrics = $dompdf->getFontMetrics();
        $font = $fontMetrics->getFont('DejaVu Sans', 'normal');
        $size = 7;
        $text = $labels('page').' {PAGE_NUM} '.$labels('of').' {PAGE_COUNT}';
        $x = ($canvas->get_width() - $fontMetrics->getTextWidth($text, $font, $size)) / 2;
        $y = $canvas->get_height() - 18;

        $canvas->page_text($x, $y, $text, $font, $size, [0.42, 0.45, 0.5]);
    }

    /** Render the AADE qrUrl as an inline base64 PNG data URI (same helper invoices use). */
    private function renderQrDataUri(string $url): string
    {
        return QrImage::dataUri($url);
    }

    /**
     * Load the tenant logo and inline it as a data URI. Identical defensive
     * handling to InvoicePdfRenderer — any filesystem-side rejection yields
     * "no logo" rather than crashing the render.
     */
    private function loadLogoDataUri(?Company $tenant): ?string
    {
        if (! $tenant || empty($tenant->logo_path)) {
            return null;
        }

        try {
            $disk = Storage::disk('public');
            if (! $disk->exists($tenant->logo_path)) {
                return null;
            }

            $bytes = $disk->get($tenant->logo_path);
        } catch (FilesystemException $e) {
            return null;
        } catch (\Throwable $e) {
            return null;
        }

        if ($bytes === null || $bytes === '') {
            return null;
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: 'application/octet-stream';

        return 'data:'.$mime.';base64,'.base64_encode($bytes);
    }
}
