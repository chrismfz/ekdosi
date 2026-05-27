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
// Throttle 60/min per IP: a healthy WHMCS-side plugin fires one
// webhook per invoice transition, far below this. A flood (slug
// enumeration, replay attack, runaway-retry bug in a misbehaving
// plugin) gets capped. Operator can bump this per-route if a
// legitimate high-traffic tenant needs more headroom.
Route::post(
    'whmcs/{slug}/invoice-paid',
    WhmcsInvoicePaidController::class,
)->middleware('throttle:60,1')->name('whmcs.invoice-paid');
