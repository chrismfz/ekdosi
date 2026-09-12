<?php

namespace App\Support\TwoFactor;

use Filament\Auth\MultiFactor\App\AppAuthentication as BaseAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Facades\Filament;
use SensitiveParameter;

/**
 * Fixes the broken enrolment QR on the profile page (2FA «Set up authenticator app»).
 *
 * Filament v5.8's {@see BaseAppAuthentication::generateQrCodeDataUri()} assumes
 * `pragmarx/google2fa-qrcode` returns RAW `<svg>` markup and — when the `imagick`
 * extension is absent (our prod hosts run gd-only) — wraps it into a
 * `data:image/svg+xml;base64,…` URI itself. But google2fa-qrcode **v4** already
 * returns a full `data:` URI from BOTH its Imagick/PNG and its SVG back-ends. So
 * Filament double-encodes it: the `<img src>` then decodes to another
 * `data:image/svg+xml;base64,…` STRING instead of SVG, the browser can't render
 * it, and only the alt text «QR code to scan with an authenticator app» shows.
 *
 * We override to pass a `data:` URI through untouched and wrap only genuine raw
 * markup — correct on both imagick (PNG) and gd-only (SVG) hosts. Drop this shim
 * if a future Filament release stops re-wrapping an already-encoded data URI.
 */
class AppAuthentication extends BaseAppAuthentication
{
    public function generateQrCodeDataUri(#[SensitiveParameter] string $secret): string
    {
        /** @var HasAppAuthentication $user */
        $user = Filament::auth()->user();

        $inlineQrCode = $this->google2FA->getQRCodeInline(
            $this->getBrandName(),
            $this->getHolderName($user),
            $secret,
        );

        // google2fa-qrcode v4 already hands back a complete data: URI — use as-is.
        if (str_starts_with($inlineQrCode, 'data:')) {
            return $inlineQrCode;
        }

        // A service that returned raw markup (older lib): wrap it exactly once.
        return 'data:image/svg+xml;base64,'.base64_encode($inlineQrCode);
    }
}
