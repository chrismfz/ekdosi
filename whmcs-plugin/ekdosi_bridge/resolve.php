<?php

/**
 * T-1 (timologia v2): READ-ONLY third-party-invoicing resolution endpoint.
 *
 * The legacy `timologia` plugin lets a WHMCS client (a reseller) route a
 * specific service's invoice to a third party (the end customer) via two
 * custom tables that the standard WHMCS API does NOT expose:
 *
 *   mod_timologia          (userid, contactid, serviceid, service_type, isReceipt)
 *   mod_timologia_contacts  (id, userid, company_name, gr_vatno, vies_vatno,
 *                            tax_office, address1/2, city, postal_code, country,
 *                            description, email, telephone, comments)
 *
 * Because there's no WHMCS API for these tables, ekdosi can't read them
 * over "the API way". This bridge endpoint — running INSIDE WHMCS with
 * local Capsule access — reads them and serves the routing to ekdosi over
 * the same HMAC scheme inbound.php uses. ekdosi never touches the WHMCS DB.
 *
 * STRICTLY READ-ONLY. No writes. Safe to deploy against a live WHMCS while
 * ekdosi files to the AADE sandbox; nothing customer-facing changes.
 *
 * Request (mirrors inbound.php: POST, HMAC over the raw body):
 *   POST /modules/addons/ekdosi_bridge/resolve.php
 *   Content-Type: application/json
 *   X-Webhook-Signature: sha256=<hex hmac of raw body>
 *
 *   op = "resolve":  { "op": "resolve", "invoice_id": 1234 }
 *     → per-line routing for one WHMCS invoice.
 *   op = "resellers": { "op": "resellers" }
 *     → every WHMCS client (userid) that has >=1 routing row, with a count.
 *       Feeds ekdosi's "flag this customer routes to third parties" badge.
 *
 * Response (resolve):
 *   200 { "status":"ok", "whmcs_invoice_id":N, "userid":N, "timologia_present":bool,
 *         "lines":[ { "item_id":N, "relid":N, "type":"Domain", "service_type":"domain"|null,
 *                     "description":"...", "routed":bool, "is_receipt":bool,
 *                     "contact": null | { id, company_name, gr_vatno, vies_vatno,
 *                                         tax_office, address1, address2, city,
 *                                         postal_code, country, description, email,
 *                                         telephone } } ],
 *         "summary": { "line_count":N, "routed_lines":N, "unrouted_lines":N,
 *                      "distinct_parties":N, "multi_party":bool } }
 *
 * Response (resellers):
 *   200 { "status":"ok", "resellers":[ { "userid":N, "routes":N } ] }
 *
 * Errors mirror inbound.php's envelope + status codes:
 *   400 bad_request / empty_body / unknown_op
 *   401 invalid_signature
 *   404 invoice_not_found            (resolve, bad invoice_id)
 *   405 method_not_allowed
 *   422 secret_not_configured
 *   500 whmcs_init_not_found / query_failed
 *
 * `timologia_present=false` (and an empty resellers list) is returned cleanly
 * when the mod_timologia* tables don't exist — a tenant that never installed
 * the legacy plugin (e.g. a non-Greek tenant). NOT an error.
 */
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

// Shared secret from tbladdonmodules (same row inbound.php uses). 422 ==
// "configured to exist but not yet set up" (distinct from 401 bad sig).
$secret = (string) (Capsule::table('tbladdonmodules')
    ->where('module', 'ekdosi_bridge')
    ->where('setting', 'webhook_secret')
    ->value('value') ?? '');
if ($secret === '') {
    http_response_code(422);
    echo json_encode([
        'error' => 'secret_not_configured',
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

// mod_timologia* are CUSTOM plugin tables; a tenant that never installed the
// legacy timologia plugin won't have them. Treat absence as "no routing" —
// every line bills the WHMCS client (today's behaviour), not an error.
$timologiaPresent = ekdosi_bridge_has_timologia_tables();

try {
    if ($op === 'resellers') {
        echo json_encode(ekdosi_bridge_resellers($timologiaPresent));
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
        echo json_encode(ekdosi_bridge_resolve_invoice($invoice, $timologiaPresent));
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'unknown_op', 'message' => 'op must be "resolve" or "resellers".']);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'query_failed', 'message' => $e->getMessage()]);
    exit;
}

/**
 * Does this WHMCS install have the legacy timologia tables? Both must exist
 * for routing to be resolvable.
 */
function ekdosi_bridge_has_timologia_tables(): bool
{
    try {
        $schema = Capsule::schema();

        return $schema->hasTable('mod_timologia') && $schema->hasTable('mod_timologia_contacts');
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Map a WHMCS line-item `type` to the legacy mod_timologia.service_type
 * vocabulary ('hosting'/'domain'). Returns null for line types the routing
 * plugin never tracked (Addon, Upgrade, Invoice, manual lines …) — those
 * always bill the WHMCS client.
 */
function ekdosi_bridge_service_type(string $whmcsItemType): ?string
{
    $t = strtolower($whmcsItemType);
    if (str_contains($t, 'hosting')) {
        return 'hosting';
    }
    if (str_contains($t, 'domain')) {
        return 'domain';
    }

    return null;
}

/**
 * Shape a mod_timologia_contacts row into the response contact object.
 * Returns the values AS STORED (WHMCS stores some as HTML entities, e.g.
 * "Σ&amp;Φ ΟΕ"); ekdosi decodes at the point it materialises a Customer,
 * keeping this endpoint a faithful mirror.
 */
function ekdosi_bridge_contact_array($row): array
{
    return [
        'id' => (int) $row->id,
        'company_name' => (string) ($row->company_name ?? ''),
        'gr_vatno' => (string) ($row->gr_vatno ?? ''),
        'vies_vatno' => (string) ($row->vies_vatno ?? ''),
        'tax_office' => (string) ($row->tax_office ?? ''),
        'address1' => (string) ($row->address1 ?? ''),
        'address2' => (string) ($row->address2 ?? ''),
        'city' => (string) ($row->city ?? ''),
        'postal_code' => (string) ($row->postal_code ?? ''),
        'country' => (string) ($row->country ?? ''),
        'description' => (string) ($row->description ?? ''),
        'email' => (string) ($row->email ?? ''),
        'telephone' => (string) ($row->telephone ?? ''),
    ];
}

/**
 * Per-line routing for one invoice. Joins each line's (userid, serviceid,
 * service_type) against mod_timologia → mod_timologia_contacts.
 */
function ekdosi_bridge_resolve_invoice($invoice, bool $timologiaPresent): array
{
    $userId = (int) $invoice->userid;
    $items = Capsule::table('tblinvoiceitems')
        ->where('invoiceid', $invoice->id)
        ->get(['id', 'type', 'relid', 'description']);

    $lines = [];
    $routedLines = 0;
    $partyKeys = [];

    foreach ($items as $item) {
        $relid = (int) ($item->relid ?? 0);
        $serviceType = ekdosi_bridge_service_type((string) ($item->type ?? ''));

        $contact = null;
        $isReceipt = false;
        if ($timologiaPresent && $serviceType !== null && $relid > 0) {
            $route = Capsule::table('mod_timologia')
                ->where('userid', $userId)
                ->where('serviceid', $relid)
                ->where('service_type', $serviceType)
                ->first();
            if ($route) {
                $isReceipt = (bool) ((int) ($route->isReceipt ?? 0));
                $contactRow = Capsule::table('mod_timologia_contacts')
                    ->where('id', (int) $route->contactid)
                    ->first();
                if ($contactRow) {
                    $contact = ekdosi_bridge_contact_array($contactRow);
                }
            }
        }

        $routed = $contact !== null;
        if ($routed) {
            $routedLines++;
            $partyKeys['contact:'.$contact['id']] = true;
        } else {
            // Unrouted line bills the reseller themselves.
            $partyKeys['reseller:'.$userId] = true;
        }

        $lines[] = [
            'item_id' => (int) $item->id,
            'relid' => $relid,
            'type' => (string) ($item->type ?? ''),
            'service_type' => $serviceType,
            'description' => (string) ($item->description ?? ''),
            'routed' => $routed,
            'is_receipt' => $isReceipt,
            'contact' => $contact,
        ];
    }

    $distinctParties = count($partyKeys);

    return [
        'status' => 'ok',
        'whmcs_invoice_id' => (int) $invoice->id,
        'userid' => $userId,
        'timologia_present' => $timologiaPresent,
        'lines' => $lines,
        'summary' => [
            'line_count' => count($lines),
            'routed_lines' => $routedLines,
            'unrouted_lines' => count($lines) - $routedLines,
            'distinct_parties' => $distinctParties,
            'multi_party' => $distinctParties > 1,
        ],
    ];
}

/**
 * Every WHMCS client with >=1 routing row, with the route count. ekdosi
 * badges the matching Customers (by whmcs_client_id) so an operator can
 * tell at a glance "this customer routes some invoices to third parties".
 */
function ekdosi_bridge_resellers(bool $timologiaPresent): array
{
    if (! $timologiaPresent) {
        return ['status' => 'ok', 'resellers' => []];
    }

    $rows = Capsule::table('mod_timologia')
        ->select('userid', Capsule::raw('COUNT(*) AS routes'))
        ->groupBy('userid')
        ->get();

    $resellers = [];
    foreach ($rows as $row) {
        $resellers[] = [
            'userid' => (int) $row->userid,
            'routes' => (int) $row->routes,
        ];
    }

    return ['status' => 'ok', 'resellers' => $resellers];
}
