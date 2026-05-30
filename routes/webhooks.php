<?php

use App\Http\Controllers\Webhooks\WhmcsClientInvoiceMapController;
use App\Http\Controllers\Webhooks\WhmcsInvoicePaidController;
use App\Http\Controllers\Webhooks\WhmcsInvoicesByAfmController;
use App\Http\Controllers\Webhooks\WhmcsInvoiceStatesController;
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

/**
 * Visibility: the 3-way mapping (WHMCS # → ekdosi παραστατικό → ΜΑΡΚ/state) for
 * a whole WHMCS client, rendered by the plugin on the admin client profile.
 * Same HMAC scheme as invoice-status, canonical "{slug}:map:{whmcs_userid}".
 * Read-only; surfaces drafts too (παραστατικό exists, not yet filed).
 */
Route::get(
    'whmcs/{slug}/invoice-map/{whmcs_userid}',
    WhmcsClientInvoiceMapController::class,
)->middleware('throttle:120,1')
    ->where('whmcs_userid', '[0-9]+')
    ->name('whmcs.invoice-map');

/**
 * Visibility (AFM-keyed): the per-client "Παραστατικά ekdosi" card, matched on
 * ΑΦΜ — the only link that survives the legacy import (no stored WHMCS↔ekdosi
 * id; verified NULL/empty in prod). POST because the body carries an ΑΦΜ SET
 * (client's own + third-party routed contacts); HMAC over the raw body, same
 * scheme as invoice-paid. Read-only; lights up imported VALID invoices with no
 * re-import. Throttle matches the other read endpoints (plugin polls on a
 * client-profile view).
 */
Route::post(
    'whmcs/{slug}/invoices-by-afm',
    WhmcsInvoicesByAfmController::class,
)->middleware('throttle:120,1')->name('whmcs.invoices-by-afm');

/**
 * Batch state lookup for the addon's consolidated invoice list: the plugin
 * reads its own tblinvoices and asks ekdosi, in ONE call, for the deterministic
 * state of those WHMCS invoice ids (ΤΠΥ + ΜΑΡΚ + κατάσταση). POST (the body
 * carries an id list); HMAC over the raw body, same scheme as invoices-by-afm.
 * Read-only.
 */
Route::post(
    'whmcs/{slug}/invoice-states',
    WhmcsInvoiceStatesController::class,
)->middleware('throttle:120,1')->name('whmcs.invoice-states');
