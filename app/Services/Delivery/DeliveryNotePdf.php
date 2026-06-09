<?php

namespace App\Services\Delivery;

use App\Models\Company;
use App\Models\DeliveryNote;
use App\Support\MyData\QrImage;
use Barryvdh\DomPDF\Facade\Pdf;
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

        $previousMemory = ini_get('memory_limit');
        $previousTime = ini_get('max_execution_time');

        try {
            @ini_set('memory_limit', self::RENDER_MEMORY_LIMIT);
            @set_time_limit(self::RENDER_TIME_LIMIT_SECONDS);

            return Pdf::loadView('delivery-notes.pdf', [
                'note' => $note,
                'tenant' => $note->company,
                'qrDataUri' => $qrDataUri,
                'logoDataUri' => $logoDataUri,
                // Two audit trails, printed when present: the movement lifecycle
                // (carrier/recipient events; events() already orders oldest→newest)
                // + the myDATA submission marks.
                'events' => $note->events()->get(),
                'marks' => $note->marks()->oldest()->get(),
            ])
                ->setPaper('A4', 'portrait')
                ->output();
        } finally {
            @ini_set('memory_limit', $previousMemory);
            @set_time_limit((int) $previousTime);
        }
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
