<?php

use App\Http\Controllers\Webhooks\EurobankReturnController;
use App\Http\Controllers\Webhooks\WhmcsClientInvoiceMapController;
use App\Http\Controllers\Webhooks\WhmcsClientIssuedInvoicesController;
use App\Http\Controllers\Webhooks\WhmcsClientIssuedPdfController;
use App\Http\Controllers\Webhooks\WhmcsInvoicePaidController;
use App\Http\Controllers\Webhooks\WhmcsInvoicesByAfmController;
use App\Http\Controllers\Webhooks\WhmcsInvoicesByLegacyIdController;
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

/**
 * Deterministic HISTORICAL lookup, keyed by legacy id. The legacy auto-invoicer
 * wrote the legacy ekdosi INVOICE_ID into WHMCS tblinvoices.invoiced; the ETL
 * kept that same id as invoices.legacy_id. So the plugin can send the `invoiced`
 * values of already-filed WHMCS invoices and get back ΤΠΥ + ΜΑΡΚ for each —
 * lighting up the ~thousands of imported invoices with NO re-import and NO ΑΦΜ
 * guessing (exact FK, not heuristic). POST (body carries a legacy-id list);
 * HMAC over the raw body, same scheme as invoice-states. Read-only.
 */
Route::post(
    'whmcs/{slug}/invoices-by-legacy-id',
    WhmcsInvoicesByLegacyIdController::class,
)->middleware('throttle:120,1')->name('whmcs.invoices-by-legacy-id');

/**
 * Customer-facing (via WHMCS): the "Εκδοθέντα Παραστατικά" list a reseller sees
 * in their own WHMCS client area. Returns every ISSUED ekdosi παραστατικό
 * produced from a WHMCS invoice that reseller paid — their own AND the ones
 * routed to third parties they set up (decision (B)) — folding BOTH the
 * bridge-derived rows (pending_whmcs_invoices, keyed by whmcs_userid; ekdosi is
 * the authority since a split maps one WHMCS invoice to many παραστατικά) AND
 * the pre-bridge historical rows (matched by the deterministic
 * invoices.whmcs_invoice_id FK against the reseller's own WHMCS invoice ids,
 * which the plugin sends in the body). POST (the body carries the id list + the
 * userid); HMAC over the raw body, same scheme as invoices-by-afm. Read-only.
 */
Route::post(
    'whmcs/{slug}/issued-for-client',
    WhmcsClientIssuedInvoicesController::class,
)->middleware('throttle:120,1')->name('whmcs.issued-for-client');

/**
 * Customer-facing (via WHMCS): stream ONE issued invoice's official PDF for the
 * plugin to proxy to the reseller (so the signed public URL never reaches the
 * browser). Membership is re-derived here (the invoice must be reachable from a
 * pending row with this whmcs_userid) and the document must be publicly
 * viewable — every failure is a flat 404. Canonical
 * "{slug}:issued-pdf:{whmcs_userid}:{invoice_id}" (the invoice id is bound in so
 * a signature can't be replayed across documents).
 */
Route::get(
    'whmcs/{slug}/issued-doc-pdf/{whmcs_userid}/{invoice}',
    WhmcsClientIssuedPdfController::class,
)->middleware('throttle:120,1')
    ->where('whmcs_userid', '[0-9]+')
    ->where('invoice', '[0-9]+')
    ->name('whmcs.issued-doc-pdf');

/**
 * Payment gateways (Πυλώνας B / B1) — Eurobank / Cardlink vPOS return. The
 * acquirer's hosted page redirect-POSTs the transaction result here (the
 * customer's browser carries it), so it's in this CSRF-exempt group: the vPOS
 * DIGEST is the auth. The controller verifies the digest against the intent's
 * connection secret, cross-checks amount/currency/company, and settles once
 * (idempotent) only on a signed CAPTURED status — then redirects the browser to
 * the portal status page. `?result=success|failure` is advisory; only the signed
 * `status` field decides. Throttled against replay/probing floods.
 */
Route::post('payments/eurobank/return', EurobankReturnController::class)
    ->middleware('throttle:120,1')
    ->name('payments.eurobank.return');
