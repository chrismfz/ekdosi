<?php
/**
 * Stage B-3: ekdosi → WHMCS write-back endpoint.
 *
 * Ekdosi POSTs here AFTER successfully filing an invoice at AADE.
 * Request shape:
 *   POST /modules/addons/ekdosi_bridge/inbound.php
 *   Content-Type: application/json
 *   X-Webhook-Signature: sha256=<hex hmac of raw body>
 *   { "whmcs_invoice_id": 8888, "mark": "999000111" }
 *
 * Authenticated via HMAC over the raw body using the shared secret
 * configured on the addon's module config page (the same secret
 * ekdosi has on its companies.whmcs_webhook_secret column).
 *
 * Effect: sets tblinvoices.invoiced = <mark> for the given invoice
 * id. Mirrors the legacy prepare_for_ekdosi plugin's direct DB
 * write — WHMCS's native UpdateInvoice API doesn't expose the
 * invoiced column.
 *
 * Response shape:
 *   200 OK { "status": "ok", "whmcs_invoice_id": N, "invoiced": "<mark>" }
 *   400 { "error": "bad_request", "message": "..." }
 *   401 { "error": "invalid_signature" }
 *   404 { "error": "invoice_not_found" }
 *   422 { "error": "secret_not_configured" }
 *   500 { "error": "db_update_failed", "message": "..." }
 *
 * Distinct status codes give the ekdosi-side WhmcsBridgeClient
 * something useful to log; today the filer treats every non-2xx
 * as a generic write-back failure but future automation (auto-retry
 * on 5xx, alert on 401) can branch on the error field.
 *
 * IMPORTANT — this file MUST live at the well-known path the
 * ekdosi-side Company::whmcsBridgeUrl() derives from
 * whmcs_api_url. Don't rename or move without updating that
 * derivation logic.
 *
 * IMPORTANT — column-width caveat: WHMCS's tblinvoices.invoiced is
 * SMALLINT(5) by default (range 0..65535). AADE MARKs are 15-digit
 * positive integers. The deploy runbook documents the required
 * ALTER TABLE to widen this column to BIGINT before the bridge can
 * write real MARKs. If the column is still SMALLINT this endpoint
 * will return 500 with the truncation error from MySQL.
 */

// Bootstrap WHMCS. This file is hit directly (not via WHMCS's
// addonmodules.php router), so we have to load WHMCS ourselves.
$bootPath = realpath(__DIR__.'/../../../init.php');
if ($bootPath === false || ! file_exists($bootPath)) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'whmcs_init_not_found']);
    exit;
}
require_once $bootPath;

use WHMCS\Database\Capsule;

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'method_not_allowed', 'message' => 'POST only.']);
    exit;
}

$rawBody = file_get_contents('php://input');
if ($rawBody === false || $rawBody === '') {
    http_response_code(400);
    echo json_encode(['error' => 'empty_body']);
    exit;
}

// Pull secret from tbladdonmodules. If the operator hasn't configured
// the addon yet there's no secret to verify against — 422 explicitly
// signals "we exist but aren't ready" (vs 401 which means "we exist
// AND we tried but your signature doesn't match").
$secretRow = Capsule::table('tbladdonmodules')
    ->where('module', 'ekdosi_bridge')
    ->where('setting', 'webhook_secret')
    ->value('value');
$secret = (string) ($secretRow ?? '');
if ($secret === '') {
    http_response_code(422);
    echo json_encode([
        'error'   => 'secret_not_configured',
        'message' => 'Addon has no webhook_secret. Configure it via the Ekdosi Bridge admin page first.',
    ]);
    exit;
}

// HMAC verification, raw-body shape. Header MUST be present AND
// match — no fallthrough on either absence.
$sigHeader = (string) ($_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? '');
if (! str_starts_with($sigHeader, 'sha256=')) {
    http_response_code(401);
    echo json_encode(['error' => 'invalid_signature', 'message' => 'X-Webhook-Signature header missing or malformed.']);
    exit;
}
$sent = substr($sigHeader, strlen('sha256='));
$expected = hash_hmac('sha256', $rawBody, $secret);
if (! hash_equals($expected, $sent)) {
    http_response_code(401);
    echo json_encode(['error' => 'invalid_signature']);
    exit;
}

$payload = json_decode($rawBody, true);
if (! is_array($payload)) {
    http_response_code(400);
    echo json_encode(['error' => 'bad_request', 'message' => 'Body must be JSON.']);
    exit;
}
$whmcsInvoiceId = (int) ($payload['whmcs_invoice_id'] ?? 0);
$mark = (string) ($payload['mark'] ?? '');
if ($whmcsInvoiceId <= 0 || $mark === '') {
    http_response_code(400);
    echo json_encode([
        'error'   => 'bad_request',
        'message' => 'Body must be {"whmcs_invoice_id": <int>, "mark": <string>}.',
    ]);
    exit;
}

// Look up the invoice. 404 distinguishes "data error on ekdosi side"
// from "WHMCS-side DB problem" — operator can dig into the right
// half.
$invoice = Capsule::table('tblinvoices')->find($whmcsInvoiceId);
if (! $invoice) {
    http_response_code(404);
    echo json_encode([
        'error'            => 'invoice_not_found',
        'whmcs_invoice_id' => $whmcsInvoiceId,
    ]);
    exit;
}

// Cast to int for the DB column. tblinvoices.invoiced may need to
// be widened from SMALLINT to BIGINT to hold 15-digit AADE MARKs;
// see the README for the ALTER TABLE.
try {
    Capsule::table('tblinvoices')
        ->where('id', $whmcsInvoiceId)
        ->update(['invoiced' => (int) $mark]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'db_update_failed', 'message' => $e->getMessage()]);
    exit;
}

// Activity log — operator sees a record of the bridge-driven write
// in WHMCS's audit trail alongside their own actions.
if (function_exists('logActivity')) {
    logActivity("EkdosiBridge: ekdosi filed invoice #{$whmcsInvoiceId} at AADE; set tblinvoices.invoiced={$mark}.");
}

http_response_code(200);
echo json_encode([
    'status'           => 'ok',
    'whmcs_invoice_id' => $whmcsInvoiceId,
    'invoiced'         => $mark,
]);
