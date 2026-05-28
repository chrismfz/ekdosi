<?php

use App\Http\Controllers\Webhooks\WhmcsInvoicePaidController;
use App\Http\Controllers\Webhooks\WhmcsInvoiceStatusController;
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

/**
 * Stage B-3 (PR #48): outbound status query the ekdosi_bridge plugin
 * uses to render "what does ekdosi know about this invoice" badges
 * on the WHMCS admin invoice page. Authenticates via HMAC over the
 * request path (no body in GET).
 *
 * Throttle 120/min — higher than the POST endpoint because the
 * plugin polls this on every WHMCS admin invoice page view, which
 * could spike if an admin opens many invoices in quick succession.
 */
Route::get(
    'whmcs/{slug}/invoice-status/{whmcs_invoice_id}',
    WhmcsInvoiceStatusController::class,
)->middleware('throttle:120,1')
    ->where('whmcs_invoice_id', '[0-9]+')
    ->name('whmcs.invoice-status');
