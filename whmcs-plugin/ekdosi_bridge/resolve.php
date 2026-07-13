<?php

/**
 * T-1 (timologia v2): READ-ONLY third-party-invoicing resolution endpoint.
 *
 * Serves ekdosi the per-line third-party routing for a WHMCS invoice (which
 * the standard WHMCS API can't expose). Runs INSIDE WHMCS with local Capsule
 * access and authenticates with the same HMAC scheme as inbound.php; ekdosi
 * never touches the WHMCS DB.
 *
 * As of T-1b-2 it reads the bridge's OWN tables (mod_ekdosi_contacts /
 * mod_ekdosi_routing), seeded by the admin "Sync from legacy timologia" action
 * (ThirdPartyStore). The legacy mod_timologia* tables are only READ during
 * that sync — the legacy plugin keeps owning them. STRICTLY READ-ONLY here
 * (no DDL, no writes); safe against a live WHMCS.
 *
 * Request (POST, HMAC over the raw body):
 *   op = "resolve":        { "op": "resolve", "invoice_id": 1234 }
 *   op = "resellers":      { "op": "resellers" }
 *   op = "invoiced_flags": { "op": "invoiced_flags", "ids": [1,2,3] }
 *                          → { "flags": { "1": 0, "2": 1, ... } } (legacy
 *                            tblinvoices.invoiced per id; READ-ONLY)
 *   op = "legacy_invoice_links": { "op": "legacy_invoice_links", "offset": 0, "limit": 500 }
 *                          → { "links": [{"whmcs_id": N, "invoiced": M}, ...] }
 *                            (invoiced > 0 only — the legacy INVOICE_ID per
 *                            WHMCS invoice; powers ekdosi's historical backfill)
 *   op = "invoices":     { "op": "invoices", "status": "paid_unfiled", "since": "Y-m-d",
 *                          "offset": 0, "limit": 100 }
 *                          → { "invoices": [ {full payload}, ... ], offset, count }
 *                            (the inbox FEED: invoice + client + customfields +
 *                            line items, shape-compatible with the native
 *                            getInvoiceWithClient — see InvoiceFeed)
 *   op = "invoice":      { "op": "invoice", "invoice_id": 1234, "with_routing": false }
 *                          → { "invoice": {full payload} | null }
 *                            (single-invoice twin of "invoices" — the push path
 *                            fetches one invoice from us instead of the WHMCS API)
 *   op = "custom_fields": { "op": "custom_fields" }
 *                          → { "fields": [{id, fieldname, adminonly}, ...] }
 *                            (the client custom-field catalogue so ekdosi can map
 *                            role→field id via a picker, not hand-typed ids)
 *
 * Response shapes + error envelope are unchanged from T-1a (the ekdosi-side
 * ThirdPartyResolution contract): see ThirdPartyStore::resolveInvoice/resellers.
 * `timologia_present` now means "the own routing tables exist".
 */

$bootPath = realpath(__DIR__.'/../../../init.php');
if ($bootPath === false || ! file_exists($bootPath)) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'whmcs_init_not_found']);
    exit;
}
require_once $bootPath;
require_once __DIR__.'/lib/ThirdPartyStore.php';
require_once __DIR__.'/lib/InvoiceFeed.php';
require_once __DIR__.'/lib/BridgeLogStore.php';

use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\EkdosiBridge\BridgeLogStore;
use WHMCS\Module\Addon\EkdosiBridge\InvoiceFeed;
use WHMCS\Module\Addon\EkdosiBridge\ThirdPartyStore;

header('Content-Type: application/json');

// Plugin-API visibility: record EVERY request (success, auth-failure, unknown
// op, exception) to mod_ekdosi_bridge_log so the WHMCS-side «Bridge logs» tab
// shows what ekdosi asks, how often, and what fails. A shutdown function logs
// once at the very end — it fires even after exit(), so it captures the final
// HTTP status without per-branch plumbing. $bridgeLogOp/$bridgeLogResult are set
// as the request is dispatched; result detail (count / found / reason) is
// optional. Best-effort — BridgeLogStore::record never throws into the response.
$bridgeLogIp = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
$bridgeLogOp = '';
$bridgeLogResult = '';
// Only record a row once the request is a PLAUSIBLE bridge call (POST + body) —
// so a random scanner GET / empty probe can't write unbounded rows to
// mod_ekdosi_bridge_log (storage amplification) nor pollute the freshness
// tripwire. Auth FAILURES (401/422) are past this gate and DO log — that's the
// secret-mismatch visibility we want.
$bridgeLogShouldRecord = false;
register_shutdown_function(static function () use (&$bridgeLogOp, &$bridgeLogResult, &$bridgeLogShouldRecord, $bridgeLogIp) {
    if (! $bridgeLogShouldRecord) {
        return;
    }
    $status = http_response_code();
    $status = is_int($status) ? $status : 200;
    BridgeLogStore::record($bridgeLogOp, $bridgeLogIp, $status >= 200 && $status < 300, $status, $bridgeLogResult);
});

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
$bridgeLogShouldRecord = true;   // POST with a body → a real bridge call attempt

// Shared secret from tbladdonmodules (same row inbound.php uses). 422 ==
// "configured to exist but not yet set up" (distinct from 401 bad sig).
$secret = (string) (Capsule::table('tbladdonmodules')
    ->where('module', 'ekdosi_bridge')
    ->where('setting', 'webhook_secret')
    ->value('value') ?? '');
if ($secret === '') {
    $bridgeLogOp = '(auth)';
    $bridgeLogResult = 'secret_not_configured';
    http_response_code(422);
    echo json_encode([
        'error'   => 'secret_not_configured',
        'message' => 'Addon has no webhook_secret. Configure it via the Ekdosi Bridge admin page first.',
    ]);
    exit;
}

// HMAC over the raw body — identical scheme to inbound.php.
$sigHeader = (string) ($_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? '');
if (! str_starts_with($sigHeader, 'sha256=')) {
    $bridgeLogOp = '(auth)';
    $bridgeLogResult = 'invalid_signature (missing/malformed header)';
    http_response_code(401);
    echo json_encode(['error' => 'invalid_signature', 'message' => 'X-Webhook-Signature header missing or malformed.']);
    exit;
}
$sent = substr($sigHeader, strlen('sha256='));
$expected = hash_hmac('sha256', $rawBody, $secret);
if (! hash_equals($expected, $sent)) {
    $bridgeLogOp = '(auth)';
    $bridgeLogResult = 'invalid_signature (HMAC mismatch)';
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

$op = (string) ($payload['op'] ?? '');
$bridgeLogOp = $op !== '' ? $op : '?';

try {
    if ($op === 'resellers') {
        echo json_encode(ThirdPartyStore::resellers());
        exit;
    }

    if ($op === 'invoiced_flags') {
        // READ-ONLY: the legacy `tblinvoices.invoiced` flag for a batch of
        // invoice ids. ekdosi uses this to show "already invoiced in the legacy
        // app" on its WHMCS inbox during the dual-run. We read the column
        // DIRECTLY (reliable — the WHMCS API doesn't expose this custom column)
        // and never write it. Real-world values are {0 (prepare_for_ekdosi),
        // 1 (WHMCS native / our rollback), <15-digit MARK> (old bridge, pre
        // rollback)} — ekdosi collapses anything > 0 to a boolean.
        $rawIds = $payload['ids'] ?? [];
        if (! is_array($rawIds)) {
            $rawIds = [];
        }
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $rawIds),
            static fn (int $id): bool => $id > 0
        )));
        $flags = [];
        if ($ids !== []) {
            // Cap to keep a single query bounded; ekdosi pages its inbox anyway.
            foreach (Capsule::table('tblinvoices')
                ->whereIn('id', array_slice($ids, 0, 500))
                ->get(['id', 'invoiced']) as $row) {
                $flags[(string) (int) $row->id] = (int) ($row->invoiced ?? 0);
            }
        }
        echo json_encode(['status' => 'ok', 'flags' => (object) $flags]);
        exit;
    }

    if ($op === 'invoices') {
        // Slice 1 — the bridge IS the inbox feed: a page of full invoice
        // payloads (invoice + client identity + customfields + line items),
        // shape-compatible with the native getInvoiceWithClient so the ekdosi
        // ingestor consumes it unchanged. Server-side filtered (paid+unfiled by
        // default) + paginated — replaces the native API's 1+2N round-trips.
        $status = (string) ($payload['status'] ?? 'paid_unfiled');
        $since = isset($payload['since']) ? (string) $payload['since'] : null;
        $offset = (int) ($payload['offset'] ?? 0);
        $limit = (int) ($payload['limit'] ?? 100);
        $withRouting = (bool) ($payload['with_routing'] ?? false);
        $feed = InvoiceFeed::fetch($status, $since, $offset, $limit, $withRouting);
        $bridgeLogResult = 'count='.(int) ($feed['count'] ?? 0).' offset='.$offset;
        echo json_encode(['status' => 'ok'] + $feed);
        exit;
    }

    if ($op === 'invoice') {
        // Plugin-API: the single-invoice twin of op=invoices. Returns the SAME
        // rich payload for ONE id (no status filter — the push path targets a
        // specific invoice the operator chose), so the ekdosi push controller
        // fetches it from us instead of the native WHMCS API. `invoice` is null
        // when the id is unknown (ekdosi maps that to "not found" → 409); we keep
        // 200 here so the client needs no special 404 handling.
        $invoiceId = (int) ($payload['invoice_id'] ?? 0);
        if ($invoiceId <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'bad_request', 'message' => 'invoice requires {"invoice_id": <int>}.']);
            exit;
        }
        $withRouting = (bool) ($payload['with_routing'] ?? false);
        $one = InvoiceFeed::fetchOne($invoiceId, $withRouting);
        $bridgeLogResult = ($one !== null ? 'found' : 'not_found').' #'.$invoiceId;
        echo json_encode(['status' => 'ok', 'invoice' => $one]);
        exit;
    }

    if ($op === 'legacy_invoice_links') {
        // READ-ONLY, paginated: (whmcs_id, invoiced) for invoices the LEGACY app
        // filed — invoiced holds the legacy ekdosi INVOICE_ID (the ETL kept it as
        // invoices.legacy_id). ekdosi's whmcs:backfill-invoice-ids pages through
        // this and stamps invoices.whmcs_invoice_id where legacy_id = invoiced.
        // Only invoiced > 0 (skip the -1000/-333/-1 sentinels and 0=unfiled).
        $offset = max(0, (int) ($payload['offset'] ?? 0));
        $limit = (int) ($payload['limit'] ?? 500);
        if ($limit < 1) {
            $limit = 500;
        }
        if ($limit > 1000) {
            $limit = 1000;
        }
        $rows = Capsule::table('tblinvoices')
            ->where('invoiced', '>', 0)
            ->orderBy('id')
            ->offset($offset)
            ->limit($limit)
            ->get(['id', 'invoiced']);
        $links = [];
        foreach ($rows as $row) {
            $links[] = ['whmcs_id' => (int) $row->id, 'invoiced' => (int) $row->invoiced];
        }
        echo json_encode([
            'status' => 'ok',
            'links' => $links,
            'offset' => $offset,
            'count' => count($links),
        ]);
        exit;
    }

    if ($op === 'resolve') {
        $invoiceId = (int) ($payload['invoice_id'] ?? 0);
        if ($invoiceId <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'bad_request', 'message' => 'resolve requires {"invoice_id": <int>}.']);
            exit;
        }
        $invoice = Capsule::table('tblinvoices')->find($invoiceId);
        if (! $invoice) {
            http_response_code(404);
            echo json_encode(['error' => 'invoice_not_found', 'whmcs_invoice_id' => $invoiceId]);
            exit;
        }
        echo json_encode(ThirdPartyStore::resolveInvoice($invoice));
        exit;
    }

    if ($op === 'custom_fields') {
        // READ-ONLY catalogue of the WHMCS client custom fields (id + name +
        // adminonly), so ekdosi can map role→field via a PICKER instead of
        // hand-typed integer ids that silently break when WHMCS reassigns them.
        $rows = Capsule::table('tblcustomfields')
            ->where('type', 'client')
            ->orderBy('id')
            ->get(['id', 'fieldname', 'adminonly']);
        $fields = [];
        foreach ($rows as $r) {
            $fields[] = [
                'id' => (int) $r->id,
                'fieldname' => (string) $r->fieldname,
                'adminonly' => ! empty($r->adminonly),
            ];
        }
        $bridgeLogResult = 'count='.count($fields);
        echo json_encode(['status' => 'ok', 'fields' => $fields]);
        exit;
    }

    if ($op === 'add_payment') {
        // WRITE: mark a WHMCS invoice paid on ekdosi's behalf (the ekdosi→WHMCS
        // «σήμανση πληρωμένου»). Delegates to WHMCS's own localAPI AddInvoicePayment
        // so gateway logs / activity / auto-Paid transition all behave natively.
        // Idempotency is двойная: `transid` (WHMCS rejects a duplicate transid+
        // gateway) AND ekdosi's own pushed-marker. Only invoice-not-already-paid
        // amounts arrive here (ekdosi queries status first).
        $invoiceId = (int) ($payload['invoice_id'] ?? 0);
        $amount = (float) ($payload['amount'] ?? 0);
        $transId = (string) ($payload['transid'] ?? '');
        $gateway = (string) ($payload['gateway'] ?? 'ekdosi');
        $date = (string) ($payload['date'] ?? date('Y-m-d H:i:s'));
        if ($invoiceId <= 0 || $amount <= 0 || $transId === '') {
            http_response_code(400);
            echo json_encode(['error' => 'bad_request', 'message' => 'add_payment requires {"invoice_id": <int>, "amount": <num>, "transid": <string>}.']);
            exit;
        }

        // CAP to the WHMCS invoice's ACTUAL remaining balance (total − already
        // paid). ekdosi computes the amount from ITS view, which can exceed what
        // WHMCS still owes (e.g. the customer already part-paid in WHMCS). Paying
        // the raw amount would push the WHMCS invoice into a credit balance —
        // so we authoritatively clamp here (the plugin is the only side that can
        // see WHMCS's real remaining balance). remaining ≤ 0 → already settled.
        $invRow = Capsule::table('tblinvoices')->where('id', $invoiceId)->first(['total']);
        if ($invRow === null) {
            $bridgeLogResult = 'add_payment invoice_not_found #'.$invoiceId;
            http_response_code(404);
            echo json_encode(['error' => 'invoice_not_found', 'message' => 'No WHMCS invoice '.$invoiceId.'.']);
            exit;
        }
        $paid = (float) Capsule::table('tblaccounts')->where('invoiceid', $invoiceId)->sum('amountin');
        $remaining = round((float) $invRow->total - $paid, 2);
        if ($remaining <= 0.005) {
            $bridgeLogResult = 'add_payment already_settled #'.$invoiceId;
            echo json_encode(['status' => 'ok', 'note' => 'already_settled']);
            exit;
        }
        $payAmount = min($amount, $remaining);

        $res = localAPI('AddInvoicePayment', [
            'invoiceid' => $invoiceId,
            'transid' => $transId,
            'gateway' => $gateway,
            'amount' => number_format($payAmount, 2, '.', ''),
            'date' => $date,
        ]);

        if (($res['result'] ?? '') !== 'success') {
            $bridgeLogResult = 'add_payment error: '.(string) ($res['message'] ?? 'unknown');
            http_response_code(502);
            echo json_encode(['error' => 'whmcs_api_error', 'message' => (string) ($res['message'] ?? 'AddInvoicePayment failed')]);
            exit;
        }

        $bridgeLogResult = 'add_payment ok #'.$invoiceId;
        echo json_encode(['status' => 'ok']);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'unknown_op', 'message' => 'op must be "resolve", "resellers", "invoiced_flags", "legacy_invoice_links", "invoices", "invoice", "custom_fields" or "add_payment".']);
    exit;
} catch (\Throwable $e) {
    $bridgeLogResult = 'error: '.$e->getMessage();
    http_response_code(500);
    echo json_encode(['error' => 'query_failed', 'message' => $e->getMessage()]);
    exit;
}
