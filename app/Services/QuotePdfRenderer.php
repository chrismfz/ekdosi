<?php

namespace App\Services;

use App\Models\Quote;
use App\Support\Pdf\PdfLabels;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

/**
 * Renders a Προσφορά to a PDF byte string for download / email. Forked from
 * InvoicePdfRenderer but stripped of the legal bits: NO QR, NO myDATA MARK,
 * NO withholding. Adds the proposal text (top) + customer notes (footer).
 *
 * Plain service (not queued): the download action runs it inline, the mail
 * job runs it on the queue.
 */
class QuotePdfRenderer
{
    private const RENDER_MEMORY_LIMIT = '512M';

    private const RENDER_TIME_LIMIT_SECONDS = 60;

    public function render(Quote $quote): string
    {
        $quote->loadMissing(['lines', 'customer', 'company']);

        $logoDataUri = $this->loadLogoDataUri($quote->company);

        $previousMemory = ini_get('memory_limit');
        $previousTime = ini_get('max_execution_time');

        try {
            @ini_set('memory_limit', self::RENDER_MEMORY_LIMIT);
            @set_time_limit(self::RENDER_TIME_LIMIT_SECONDS);

            return Pdf::loadView('quotes.pdf', [
                'quote' => $quote,
                'tenant' => $quote->company,
                'logoDataUri' => $logoDataUri,
                'totals' => $this->totalsView($quote),
                'L' => PdfLabels::for(PdfLabels::resolveLanguage($quote->language, $quote->country)),
            ])
                ->setPaper('A4', 'portrait')
                ->output();
        } finally {
            @ini_set('memory_limit', $previousMemory);
            @set_time_limit((int) $previousTime);
        }
    }

    private function totalsView(Quote $quote): array
    {
        return [
            'rows' => QuoteTotals::vatRows($quote),
            'totalNet' => (float) $quote->net_total,
            'totalVat' => (float) $quote->vat_total,
            'totalGross' => (float) $quote->gross_total,
        ];
    }

    private function loadLogoDataUri(?\App\Models\Company $tenant): ?string
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
        } catch (\League\Flysystem\FilesystemException $e) {
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
