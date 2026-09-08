<?php

namespace App\Services\Cmr;

use App\Models\CmrNote;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Renders a CMR consignment note to PDF bytes via DomPDF + the Blade in
 * resources/views/cmr/pdf.blade.php — a faithful single-A4 reproduction of the
 * standard 24-box form (docs/reference/cmr-template.pdf), English labels.
 *
 * Mirrors DeliveryNotePdf's per-render ini guard. NO myDATA QR/MARK — a CMR is a
 * transport document, not a filed παραστατικό.
 */
class CmrPdf
{
    private const RENDER_MEMORY_LIMIT = '512M';

    private const RENDER_TIME_LIMIT_SECONDS = 60;

    public function render(CmrNote $cmr): string
    {
        $cmr->loadMissing(['lines', 'company']);

        $previousMemory = ini_get('memory_limit');
        $previousTime = ini_get('max_execution_time');

        try {
            @ini_set('memory_limit', self::RENDER_MEMORY_LIMIT);
            @set_time_limit(self::RENDER_TIME_LIMIT_SECONDS);

            return Pdf::loadView('cmr.pdf', [
                'cmr' => $cmr,
                'tenant' => $cmr->company,
            ])
                ->setPaper('A4', 'portrait')
                ->output();
        } finally {
            @ini_set('memory_limit', $previousMemory);
            @set_time_limit((int) $previousTime);
        }
    }
}
