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

use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\EkdosiBridge\InvoiceFeed;
use WHMCS\Module\Addon\EkdosiBridge\ThirdPartyStore;

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

// Shared secret from tbladdonmodules (same row inbound.php uses). 422 ==
// "configured to exist but not yet set up" (distinct from 401 bad sig).
$secret = (string) (Capsule::table('tbladdonmodules')
    ->where('module', 'ekdosi_bridge')
    ->where('setting', 'webhook_secret')
    ->value('value') ?? '');
if ($secret === '') {
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

$op = (string) ($payload['op'] ?? '');

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
        echo json_encode(['status' => 'ok'] + InvoiceFeed::fetch($status, $since, $offset, $limit, $withRouting));
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
        echo json_encode(['status' => 'ok', 'invoice' => InvoiceFeed::fetchOne($invoiceId, $withRouting)]);
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

    http_response_code(400);
    echo json_encode(['error' => 'unknown_op', 'message' => 'op must be "resolve", "resellers", "invoiced_flags", "legacy_invoice_links", "invoices" or "invoice".']);
    exit;
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'query_failed', 'message' => $e->getMessage()]);
    exit;
}
