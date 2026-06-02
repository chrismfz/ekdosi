<?php

namespace App\Support\MyData;

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Writer\PngWriter;

/**
 * Renders a myDATA QR URL (the AADE `qrCodeUrl`) to a base64 PNG data URI.
 * Single home for the endroid/qr-code v6 builder so the invoice PDF and the
 * MARK-detail page produce the same QR (DRY — was inlined in InvoicePdfRenderer).
 */
final class QrImage
{
    /**
     * Guarded variant for view contexts: returns null instead of throwing
     * (missing GD/imagick, pathological input) so a QR failure degrades to a
     * fallback rather than 500-ing the whole page. Also null for empty/non-URL.
     */
    public static function tryDataUri(?string $url, int $size = 200): ?string
    {
        if (! is_string($url) || $url === '') {
            return null;
        }

        try {
            return self::dataUri($url, $size);
        } catch (\Throwable) {
            return null;
        }
    }

    public static function dataUri(string $url, int $size = 200): string
    {
        // endroid/qr-code v6: Builder is a final readonly class constructed
        // with all options, then build() returns a Result.
        return (new Builder(
            writer: new PngWriter(),
            data: $url,
            encoding: new Encoding('UTF-8'),
            errorCorrectionLevel: ErrorCorrectionLevel::Medium,
            size: $size,
            margin: 8,
        ))->build()->getDataUri();
    }
}
