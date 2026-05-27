<?php
/**
 * ekdosi_bridge — WHMCS addon module.
 *
 * Stage B-3: replaces the legacy `prepare_for_ekdosi` plugin with a
 * bidirectional bridge between WHMCS and the new ekdosi (Laravel +
 * Filament) backend. WHMCS-side responsibilities:
 *
 *   1. Module admin page: configure the ekdosi webhook URL + shared
 *      HMAC secret. Operator pastes both during setup.
 *
 *   2. Outbound push: from a WHMCS admin invoice page, "Send to
 *      ekdosi for review" buttons POST a minimal `{whmcs_invoice_id}`
 *      to ekdosi's webhook endpoint (HMAC-signed). Ekdosi fetches
 *      the full invoice via its own WHMCS API credentials and stages
 *      it in the pending_whmcs_invoices inbox for operator review.
 *
 *   3. Status display: "Show ekdosi status" GETs from ekdosi's
 *      status endpoint. Renders the pending row's state (pending /
 *      filed / rejected / held + MARK if filed) in the WHMCS admin
 *      sidebar.
 *
 *   4. Inbound write-back endpoint (`inbound.php`, NOT this addon's
 *      output handler): ekdosi POSTs the MARK after filing at AADE,
 *      the endpoint authenticates via the same HMAC secret and
 *      writes `tblinvoices.invoiced = <MARK>`. This replaces the
 *      legacy prepare_for_ekdosi manual UI for setting that column.
 *
 *   5. "Reset to unfiled": operator-initiated rollback that sets
 *      `tblinvoices.invoiced = 0` (rare path; the legacy
 *      prepare_for_ekdosi plugin's only feature).
 *
 * Coexistence with prepare_for_ekdosi: this plugin lives at
 * modules/addons/ekdosi_bridge/ and prepare_for_ekdosi lives at
 * modules/addons/prepare_for_ekdosi/. Both can run side-by-side
 * during the rollout. Once operators verify the bridge works end-
 * to-end, prepare_for_ekdosi can be deactivated (see README for
 * the cutover steps).
 */

use WHMCS\Module\Addon\EkdosiBridge\Admin\AdminDispatcher;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__.'/lib/Admin/AdminDispatcher.php';
require_once __DIR__.'/lib/Admin/Controller.php';
require_once __DIR__.'/lib/EkdosiClient.php';

function ekdosi_bridge_config(): array
{
    return [
        'name'        => 'Ekdosi Bridge',
        'description' => 'Push WHMCS invoices to ekdosi for AADE filing + receive MARK write-back. Replaces prepare_for_ekdosi.',
        'version'     => '0.1.0',
        'author'      => 'MyIP Networks',
        'fields'      => [
            'ekdosi_base_url' => [
                'FriendlyName' => 'Ekdosi base URL',
                'Type'         => 'text',
                'Size'         => '80',
                'Description'  => 'e.g. https://ekdosi.example.com — the host. We append /webhooks/whmcs/{slug}/... ourselves.',
            ],
            'ekdosi_slug' => [
                'FriendlyName' => 'Ekdosi tenant slug',
                'Type'         => 'text',
                'Size'         => '40',
                'Description'  => 'The slug your tenant uses in ekdosi (e.g. "myip", "nixpal"). Must match companies.slug exactly.',
            ],
            'webhook_secret' => [
                'FriendlyName' => 'Shared HMAC secret',
                'Type'         => 'password',
                'Size'         => '64',
                'Description'  => 'Must match companies.whmcs_webhook_secret on the ekdosi side, EXACTLY. Generate a 32+ char random string and paste it on both sides.',
            ],
        ],
    ];
}

function ekdosi_bridge_activate(): array
{
    return ['status' => 'success', 'description' => 'Addon activated.'];
}

function ekdosi_bridge_deactivate(): array
{
    return ['status' => 'success', 'description' => 'Addon deactivated.'];
}

/**
 * Admin module page entry. Routed by action parameter so the
 * AdminDispatcher pattern (mirrors legacy prepare_for_ekdosi) can
 * grow more pages as we ship features.
 */
function ekdosi_bridge_output($vars): void
{
    $action = isset($_REQUEST['action']) ? (string) $_REQUEST['action'] : '';
    $dispatcher = new AdminDispatcher();
    echo $dispatcher->dispatch($action, $vars);
}
