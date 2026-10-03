<?php

namespace App\Support;

use InvalidArgumentException;
use Picqer\Barcode\BarcodeGeneratorSVG;

/**
 * One place that draws 1D barcodes (picqer/php-barcode-generator) — a plain USB/
 * Bluetooth scanner reads them like a keyboard, no camera needed. Used by the
 * 80mm till receipt (its document code, so a return is one scan) and meant for
 * product / shelf labels and stock counts (EAN-13 / Code 128 of a product's
 * barcode or SKU).
 *
 * Output is an inline SVG data URI (crisp on a 203 dpi thermal head, no files,
 * no GD/Imagick).
 */
final class Barcode
{
    public const CODE_128 = 'code128';

    public const EAN_13 = 'ean13';

    /**
     * @param  float  $moduleWidth  width of the thinnest bar, in px (2 = thermal-friendly)
     */
    public static function svgDataUri(string $value, string $type = self::CODE_128, float $moduleWidth = 2.0, int $height = 48): string
    {
        $value = trim($value);
        if ($value === '') {
            throw new InvalidArgumentException('Empty barcode value.');
        }

        $generator = new BarcodeGeneratorSVG;
        $svg = $generator->getBarcode($value, match ($type) {
            self::EAN_13 => $generator::TYPE_EAN_13,
            self::CODE_128 => $generator::TYPE_CODE_128,
            default => throw new InvalidArgumentException("Unsupported barcode type «{$type}»."),
        }, $moduleWidth, $height);

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }

    /** Can this value be printed as Code 128 (printable ASCII only — no Greek)? */
    public static function fitsCode128(string $value): bool
    {
        return $value !== '' && preg_match('/^[\x20-\x7E]+$/', $value) === 1;
    }
}
