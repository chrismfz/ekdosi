<?php
/**
 * Stage B-3: ekdosi → WHMCS write-back endpoint.
 *
 * Ekdosi POSTs here AFTER successfully filing an invoice at AADE.
 * Request shape:
 *   POST /modules/addons/ekdosi_bridge/inbound.php
 *   Content-Type: application/json
 *   X-Webhook-Signature: sha256=<hex hmac of raw body>
 *   { "whmcs_invoice_id": 8888, "mark": "999000111", "invcode": "ΑΠΥ423" }
 *   (invcode is optional — the ekdosi ΤΠΥ shown next to the MARK.)
 *
 * Authenticated via HMAC over the raw body using the shared secret
 * configured on the addon's module config page (the same secret
 * ekdosi has on its companies.whmcs_webhook_secret column).
 *
 * Effect: stores the MARK in OUR OWN table mod_ekdosi_invoice_marks
 * (keyed by WHMCS invoice id). We do NOT touch tblinvoices.invoiced —
 * that is a legacy SMALLINT flag the legacy ekdosi app reads/writes,
 * and stuffing a 15-digit MARK there (the old behaviour) broke it.
 *
 * Response shape:
 *   200 OK { "status": "ok", "whmcs_invoice_id": N, "mark": "<mark>" }
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
 * ekdosi-side derives from whmcs_api_url. That path is the
 * Company::WHMCS_BRIDGE_PATH constant
 * ('/modules/addons/ekdosi_bridge/inbound.php') in the ekdosi repo.
 * Don't rename or move without updating that constant.
 *
 * Storage: the MARK is a 15-digit string kept in our own
 * mod_ekdosi_invoice_marks table (VARCHAR) — no column-width concerns,
 * and tblinvoices.invoiced stays the legacy SMALLINT flag.
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
require_once __DIR__.'/lib/InvoiceMarkStore.php';

use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\EkdosiBridge\InvoiceMarkStore;

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
// Optional: the ekdosi ΤΠΥ (e.g. ΑΠΥ423), shown next to the MARK on the admin
// badges. Absent on older ekdosi versions → stored as null, no behaviour change.
$invcode = trim((string) ($payload['invcode'] ?? ''));
// Optional: the AADE state ('active'/'cancelled'). When ekdosi cancels at AADE
// it re-pushes the SAME mark with state='cancelled' so the badge can show
// «ΑΚΥΡΩΜΕΝΟ». Absent on older ekdosi → null, badge falls back to "filed".
$state = strtolower(trim((string) ($payload['state'] ?? '')));
if (! in_array($state, ['active', 'cancelled'], true)) {
    $state = '';
}
// Optional: signed public URL to the official ekdosi παραστατικό PDF. Only
// accept http(s) so a malformed value can't become a javascript: link in a
// rendered <a>. Stored verbatim; the badge renders it htmlspecialchar'd.
$pdfUrl = trim((string) ($payload['pdf_url'] ?? ''));
if ($pdfUrl !== '' && ! preg_match('#^https?://#i', $pdfUrl)) {
    $pdfUrl = '';
}
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

// Idempotent-write guard against OUR mark store (not tblinvoices.invoiced):
//   none           -> fresh, file it
//   the SAME MARK  -> idempotent retry after a transient failure, OK
//   a DIFFERENT MARK -> REFUSE (409): overwriting would silently erase the
//   original MARK. Cancel-and-refile must go through "Reset to unfiled" first.
try {
    InvoiceMarkStore::ensureTable();
    $current = InvoiceMarkStore::get($whmcsInvoiceId);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'db_update_failed', 'message' => $e->getMessage()]);
    exit;
}
if ($current !== null && $current !== $mark) {
    http_response_code(409);
    echo json_encode([
        'error'            => 'already_filed_with_different_mark',
        'whmcs_invoice_id' => $whmcsInvoiceId,
        'current'          => $current,
        'incoming'         => $mark,
        'message'          => 'Invoice already carries a different MARK. Reset to unfiled '
            .'on the Ekdosi Bridge admin page before re-filing.',
    ]);
    exit;
}

// Persist the MARK as a STRING in our own table — never touch
// tblinvoices.invoiced (legacy SMALLINT flag).
try {
    InvoiceMarkStore::set($whmcsInvoiceId, $mark, $invcode !== '' ? $invcode : null, $state !== '' ? $state : null, $pdfUrl !== '' ? $pdfUrl : null);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'db_update_failed', 'message' => $e->getMessage()]);
    exit;
}

// Activity log — operator sees a record of the bridge-driven write
// in WHMCS's audit trail alongside their own actions.
if (function_exists('logActivity')) {
    logActivity("EkdosiBridge: ekdosi filed WHMCS invoice #{$whmcsInvoiceId} at AADE; MARK={$mark} (mod_ekdosi_invoice_marks).");
}

http_response_code(200);
echo json_encode([
    'status'           => 'ok',
    'whmcs_invoice_id' => $whmcsInvoiceId,
    'mark'             => $mark,
    'invcode'          => $invcode !== '' ? $invcode : null,
    'state'            => $state !== '' ? $state : null,
    'pdf_url'          => $pdfUrl !== '' ? $pdfUrl : null,
]);
