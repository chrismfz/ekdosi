<?php

use App\Http\Controllers\Webhooks\WhmcsInvoicePaidController;
use Illuminate\Support\Facades\Route;

/**
 * PR #31 (Stage B-1): inbound webhook routes.
 *
 * Registered from bootstrap/app.php under prefix='webhooks' and the
 * `api` middleware group (stateless, no session, no CSRF). The
 * `api` group is important because web's CSRF middleware would
 * reject every external POST without a token, and webhooks have no
 * way to obtain one.
 *
 * Routes here MUST verify their own auth (HMAC, bearer, mTLS, etc.) -
 * there is no global middleware doing it for them.
 */
Route::post(
    'whmcs/{slug}/invoice-paid',
    WhmcsInvoicePaidController::class,
)->name('whmcs.invoice-paid');
