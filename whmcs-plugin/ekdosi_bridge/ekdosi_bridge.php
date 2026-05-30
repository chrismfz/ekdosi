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

use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\EkdosiBridge\Admin\AdminDispatcher;
use WHMCS\Module\Addon\EkdosiBridge\Client\Controller;
use WHMCS\Module\Addon\EkdosiBridge\Client\Gate;
use WHMCS\Module\Addon\EkdosiBridge\ThirdPartyStore;

if (! defined('WHMCS')) {
    exit('This file cannot be accessed directly');
}

require_once __DIR__.'/lib/Admin/AdminDispatcher.php';
require_once __DIR__.'/lib/Admin/Controller.php';
require_once __DIR__.'/lib/EkdosiClient.php';
require_once __DIR__.'/lib/ThirdPartyStore.php';
require_once __DIR__.'/lib/Client/Gate.php';
require_once __DIR__.'/lib/Client/Controller.php';

function ekdosi_bridge_config(): array
{
    return [
        'name' => 'Ekdosi Bridge',
        'description' => 'Push WHMCS invoices to ekdosi for AADE filing + receive MARK write-back. Replaces prepare_for_ekdosi.',
        'version' => '0.4.0',
        'author' => 'MyIP Networks',
        'fields' => [
            'ekdosi_base_url' => [
                'FriendlyName' => 'Ekdosi base URL',
                'Type' => 'text',
                'Size' => '80',
                'Description' => 'e.g. https://ekdosi.example.com — the host. We append /webhooks/whmcs/{slug}/... ourselves.',
            ],
            'ekdosi_slug' => [
                'FriendlyName' => 'Ekdosi tenant slug',
                'Type' => 'text',
                'Size' => '40',
                'Description' => 'The slug your tenant uses in ekdosi (e.g. "myip", "nixpal"). Must match companies.slug exactly.',
            ],
            'webhook_secret' => [
                'FriendlyName' => 'Shared HMAC secret',
                'Type' => 'password',
                'Size' => '64',
                'Description' => 'Must match companies.whmcs_webhook_secret on the ekdosi side, EXACTLY. Generate a 32+ char random string and paste it on both sides.',
            ],
            // T-2: hide/reveal the client-area "Παραστατικά σε τρίτους (v2)" page.
            'show_client_v2' => [
                'FriendlyName' => 'Show client v2 page',
                'Type' => 'yesno',
                'Description' => 'Show the client-area "Παραστατικά σε τρίτους (v2)" link. OFF by default — flip on only when testing; customers see nothing while off.',
            ],
            'v2_pilot_clients' => [
                'FriendlyName' => 'v2 pilot client IDs',
                'Type' => 'text',
                'Size' => '40',
                'Description' => 'Optional. Comma-separated WHMCS client IDs. When set, ONLY these clients see/use v2 (everyone else sees nothing, even with the switch on). Leave blank for all clients.',
            ],
        ],
    ];
}

function ekdosi_bridge_activate(): array
{
    $notes = [];

    // 1. Widen tblinvoices.invoiced to BIGINT so it can hold 15-digit
    //    AADE MARKs. WHMCS ships it as SMALLINT(5) (max 65535) which
    //    truncates real MARKs. Doing this at activation (instead of a
    //    manual ALTER the operator might skip) makes the bridge work
    //    out of the box. Idempotent: re-running on an already-BIGINT
    //    column is a no-op ALTER.
    try {
        $col = Capsule::selectOne(
            "SELECT DATA_TYPE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'tblinvoices'
               AND COLUMN_NAME = 'invoiced'"
        );
        $type = $col ? strtolower((string) $col->DATA_TYPE) : '';
        if ($type !== '' && $type !== 'bigint') {
            Capsule::statement(
                'ALTER TABLE tblinvoices MODIFY invoiced BIGINT NULL DEFAULT 0'
            );
            $notes[] = "Widened tblinvoices.invoiced from {$type} to BIGINT (holds 15-digit AADE MARKs).";
        } else {
            $notes[] = 'tblinvoices.invoiced is already BIGINT (or check skipped).';
        }
    } catch (Throwable $e) {
        // Don't fail activation outright — the operator may lack ALTER
        // privileges (managed hosting). Surface the SQL so a DBA can
        // run it manually, and let the addon activate so config can
        // still be entered.
        $notes[] = 'WARNING: could not auto-widen tblinvoices.invoiced ('
            .$e->getMessage().'). Run manually before filing real MARKs: '
            .'ALTER TABLE tblinvoices MODIFY invoiced BIGINT NULL DEFAULT 0;';
    }

    // 2. Coexistence guard: warn (don't block) if the legacy
    //    prepare_for_ekdosi addon is still active. Both write to
    //    tblinvoices.invoiced with conflicting semantics ({0,1} vs
    //    {0,MARK}); running both invites a silent clobber. We warn
    //    rather than refuse so the operator can run them side-by-side
    //    intentionally during the rollout — but they're told.
    try {
        $legacyActive = Capsule::table('tbladdonmodules')
            ->where('module', 'prepare_for_ekdosi')
            ->exists();
        if ($legacyActive) {
            $notes[] = 'WARNING: prepare_for_ekdosi is also active. Both plugins write '
                .'tblinvoices.invoiced; deactivate prepare_for_ekdosi once you have '
                .'verified ekdosi_bridge end-to-end to avoid a silent overwrite.';
        }
    } catch (Throwable $e) {
        // Non-fatal: the coexistence check is advisory only.
    }

    // 3. Create the bridge's own third-party-invoicing tables
    //    (mod_ekdosi_contacts / mod_ekdosi_routing). Idempotent; seeded later
    //    by the admin "Sync from legacy timologia" action (T-1b-2).
    try {
        ThirdPartyStore::ensureTables();
        $notes[] = 'Third-party tables (mod_ekdosi_contacts / mod_ekdosi_routing) ready.';
    } catch (Throwable $e) {
        $notes[] = 'WARNING: could not create mod_ekdosi_* tables ('.$e->getMessage()
            .'). Use the "Sync from legacy timologia" admin action once DB privileges allow.';
    }

    return [
        'status' => 'success',
        'description' => 'Addon activated. '.implode(' ', $notes),
    ];
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
    $dispatcher = new AdminDispatcher;
    echo $dispatcher->dispatch($action, $vars);
}

/**
 * T-2: client-area page "Παραστατικά σε τρίτους (v2)". Gated by
 * Gate::visibleTo (the show_client_v2 switch + the optional pilot allowlist) —
 * enforced HERE too, not just on the navbar link, so a hidden page can't be
 * reached by URL-guessing.
 */
function ekdosi_bridge_clientarea($vars): array
{
    $clientId = (int) ($_SESSION['uid'] ?? 0);

    if (! Gate::visibleTo($clientId)) {
        return [
            'pagetitle' => 'Παραστατικά σε τρίτους',
            'breadcrumb' => ['index.php?m=ekdosi_bridge' => 'Παραστατικά σε τρίτους'],
            'templatefile' => 'clientpage',
            'requirelogin' => true,
            'vars' => ['pagecontent' => '<div class="alert alert-info">Η σελίδα δεν είναι διαθέσιμη.</div>'],
        ];
    }

    $controller = new Controller;
    $html = $controller->render($vars, $clientId);

    return [
        'pagetitle' => 'Παραστατικά σε τρίτους (v2)',
        'breadcrumb' => ['index.php?m=ekdosi_bridge' => 'Παραστατικά σε τρίτους (v2)'],
        'templatefile' => 'clientpage',
        'requirelogin' => true,
        'vars' => ['pagecontent' => $html],
    ];
}
