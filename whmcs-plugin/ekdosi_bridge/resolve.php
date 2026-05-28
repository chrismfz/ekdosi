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
 *   op = "resolve":   { "op": "resolve", "invoice_id": 1234 }
 *   op = "resellers": { "op": "resellers" }
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

use WHMCS\Database\Capsule;
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
    echo json_encode(['error' => 'unknown_op', 'message' => 'op must be "resolve" or "resellers".']);
    exit;
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'query_failed', 'message' => $e->getMessage()]);
    exit;
}
