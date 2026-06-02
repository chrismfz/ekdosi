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
 *      output handler): ekdosi POSTs the MARK (+ its ΤΠΥ invcode) after
 *      filing at AADE, the endpoint authenticates via the same HMAC
 *      secret and stores both in OUR OWN `mod_ekdosi_invoice_marks`
 *      table (NOT in tblinvoices.invoiced — that stays a legacy SMALLINT
 *      flag). The admin badges show "Στο AADE · ΤΠΥ … · ΜΑΡΚ …".
 *
 *   5. "Reset to unfiled": operator-initiated rollback that drops our
 *      MARK row (rare path; e.g. cancelled at AADE and re-filing).
 *
 * Coexistence with the legacy app / prepare_for_ekdosi: we now NEVER
 * write `tblinvoices.invoiced` — we only READ it (to show "Invoiced in
 * legacy app"). So the bridge runs safely alongside the legacy ekdosi
 * app during the dual-run ("test new, keep invoicing from old"): the
 * legacy side owns `invoiced`, ekdosi owns the MARK in its own table.
 * resolve.php's read-only `invoiced_flags` op serves that legacy flag in
 * batch so the ekdosi inbox can warn "already invoiced in the old app".
 */

use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\EkdosiBridge\Admin\AdminDispatcher;
use WHMCS\Module\Addon\EkdosiBridge\Client\Controller;
use WHMCS\Module\Addon\EkdosiBridge\Client\Gate;
use WHMCS\Module\Addon\EkdosiBridge\InvoiceMarkStore;
use WHMCS\Module\Addon\EkdosiBridge\ThirdPartyStore;

if (! defined('WHMCS')) {
    exit('This file cannot be accessed directly');
}

require_once __DIR__.'/lib/Admin/AdminDispatcher.php';
require_once __DIR__.'/lib/Admin/Controller.php';
require_once __DIR__.'/lib/EkdosiClient.php';
require_once __DIR__.'/lib/InvoiceMarkStore.php';
require_once __DIR__.'/lib/ThirdPartyStore.php';
require_once __DIR__.'/lib/Client/Gate.php';
require_once __DIR__.'/lib/Client/Controller.php';

function ekdosi_bridge_config(): array
{
    return [
        'name' => 'Ekdosi Bridge',
        'description' => 'Push WHMCS invoices to ekdosi for AADE filing + receive MARK write-back. Replaces prepare_for_ekdosi.',
        'version' => '0.20.0',
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

    // 1. Keep the AADE MARK in our OWN table (mod_ekdosi_invoice_marks) and
    //    RESTORE tblinvoices.invoiced to the SMALLINT the legacy ekdosi app
    //    expects. EARLIER versions of this plugin widened `invoiced` to BIGINT
    //    to stuff the 15-digit MARK in — that broke the legacy app (it reads
    //    `invoiced` as a SMALLINT {0,1} "invoiced/processed" flag). We now NEVER
    //    write `invoiced`; we only READ it (to show "Invoiced in legacy app").
    //
    //    This step is idempotent + privilege-safe:
    //      - ensure mod_ekdosi_invoice_marks exists;
    //      - if `invoiced` is BIGINT (we widened it): move every MARK out into
    //        our table, reset those rows to 1 (legacy "filed" flag, SMALLINT-
    //        safe), NULL→0, then narrow the column back to SMALLINT;
    //      - if it's already SMALLINT: leave it completely alone.
    try {
        InvoiceMarkStore::ensureTable();
        $notes[] = 'Mark table (mod_ekdosi_invoice_marks) ready.';

        $col = Capsule::selectOne(
            "SELECT DATA_TYPE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'tblinvoices'
               AND COLUMN_NAME = 'invoiced'"
        );
        $type = $col ? strtolower((string) $col->DATA_TYPE) : '';

        if ($type === 'bigint') {
            $moved = InvoiceMarkStore::migrateFromInvoicedColumn();
            // Reset the moved rows to the legacy "filed" flag so they fit
            // SMALLINT and keep the legacy "this was invoiced" meaning.
            Capsule::table('tblinvoices')->where('invoiced', '>', 65535)->update(['invoiced' => 1]);
            Capsule::statement('UPDATE tblinvoices SET invoiced = 0 WHERE invoiced IS NULL');
            Capsule::statement('ALTER TABLE tblinvoices MODIFY invoiced SMALLINT(5) NOT NULL DEFAULT 0');
            $notes[] = "Restored tblinvoices.invoiced to SMALLINT (moved {$moved} MARK(s) into "
                .'mod_ekdosi_invoice_marks; the legacy app reads `invoiced` again).';
        } elseif ($type === '') {
            $notes[] = 'tblinvoices.invoiced not found — nothing to restore.';
        } else {
            $notes[] = "tblinvoices.invoiced is {$type} (not widened by us) — left untouched.";
        }
    } catch (Throwable $e) {
        // Don't fail activation — the operator may lack ALTER privileges
        // (managed hosting). Surface the exact manual SQL so a DBA can run it.
        $notes[] = 'WARNING: could not auto-restore tblinvoices.invoiced ('
            .$e->getMessage().'). If it is BIGINT, run manually: '
            // Self-contained: include the CREATE in case ensureTable() itself
            // failed (no CREATE privilege), so the INSERT below has a target.
            .'CREATE TABLE IF NOT EXISTS mod_ekdosi_invoice_marks ('
            .'invoiceid BIGINT UNSIGNED NOT NULL PRIMARY KEY, mark VARCHAR(40) NOT NULL, '
            .'invcode VARCHAR(60) NULL DEFAULT NULL, updated_at DATETIME NULL DEFAULT NULL) '
            .'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4; '
            .'INSERT INTO mod_ekdosi_invoice_marks (invoiceid, mark, updated_at) '
            .'SELECT id, invoiced, NOW() FROM tblinvoices WHERE invoiced > 65535 '
            .'ON DUPLICATE KEY UPDATE mark = VALUES(mark); '
            .'UPDATE tblinvoices SET invoiced = 1 WHERE invoiced > 65535; '
            .'UPDATE tblinvoices SET invoiced = 0 WHERE invoiced IS NULL; '
            .'ALTER TABLE tblinvoices MODIFY invoiced SMALLINT(5) NOT NULL DEFAULT 0;';
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
