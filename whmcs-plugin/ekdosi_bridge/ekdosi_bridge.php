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

use WHMCS\Module\Addon\EkdosiBridge\Admin\AdminDispatcher;
use WHMCS\Module\Addon\EkdosiBridge\Client\Controller;
use WHMCS\Module\Addon\EkdosiBridge\Client\Gate;
use WHMCS\Module\Addon\EkdosiBridge\Client\IssuedController;
use WHMCS\Module\Addon\EkdosiBridge\SchemaGuard;

if (! defined('WHMCS')) {
    exit('This file cannot be accessed directly');
}

require_once __DIR__.'/lib/Admin/AdminDispatcher.php';
require_once __DIR__.'/lib/Admin/Controller.php';
require_once __DIR__.'/lib/EkdosiClient.php';
require_once __DIR__.'/lib/InvoiceMarkStore.php';
require_once __DIR__.'/lib/BridgeLogStore.php';
require_once __DIR__.'/lib/SchemaGuard.php';
require_once __DIR__.'/lib/ThirdPartyStore.php';
require_once __DIR__.'/lib/RelidInspector.php';
require_once __DIR__.'/lib/Client/Gate.php';
require_once __DIR__.'/lib/Client/Controller.php';
require_once __DIR__.'/lib/Client/IssuedController.php';

function ekdosi_bridge_config(): array
{
    return [
        'name' => 'Ekdosi Bridge',
        'description' => 'Push WHMCS invoices to ekdosi for AADE filing + receive MARK write-back. Replaces prepare_for_ekdosi.',
        'version' => '0.53.0',
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
                'Type' => 'text',
                'Size' => '64',
                'Description' => 'Must match companies.whmcs_webhook_secret on the ekdosi side, EXACTLY. Generate a 32+ char random string and paste it on both sides. Shown in plain text ON PURPOSE — this page is super-admin-only and the value is readable via SQL anyway — so you can copy it back if WHMCS clears the addon settings on a re-activation.',
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
            // #5b: show the official ekdosi PDF link on the CLIENT-AREA invoice
            // page (customers). OFF by default — admin-only until flipped on for
            // testing. The link is the same signed ekdosi URL the admin badge uses.
            'show_pdf_client_area' => [
                'FriendlyName' => 'Show official PDF to customers',
                'Type' => 'yesno',
                'Description' => 'Show a «Επίσημο παραστατικό (ΑΑΔΕ)» button on the client-area invoice view page. OFF by default (admin-only). Flip on to let customers open the official ekdosi PDF (signed link, only their own invoices).',
            ],
            // "Εκδοθέντα Παραστατικά" client-area page — an INDEPENDENT switch
            // from show_client_v2 (a tenant may let customers VIEW their issued
            // documents without exposing the third-party routing editor). The
            // list is fetched live from ekdosi, scoped to the logged-in client;
            // the PDF is proxied (the signed ekdosi URL never reaches the browser).
            'show_client_issued' => [
                'FriendlyName' => 'Show client "Εκδοθέντα Παραστατικά" page',
                'Type' => 'yesno',
                'Description' => 'Show the client-area «Εκδοθέντα Παραστατικά» link — the customer sees the ekdosi παραστατικά issued for them (their own + third-party routed), with official PDF + AADE verification. OFF by default; customers see nothing while off.',
            ],
            'issued_pilot_clients' => [
                'FriendlyName' => '"Εκδοθέντα" pilot client IDs',
                'Type' => 'text',
                'Size' => '40',
                'Description' => 'Optional. Comma-separated WHMCS client IDs. When set, ONLY these clients see the «Εκδοθέντα Παραστατικά» page (everyone else sees nothing, even with the switch on). Leave blank for all clients.',
            ],
            // Second knob: whether the «Εκδοθέντα» page ALSO lists pre-bridge
            // (imported) παραστατικά. Independent of the master switch so you can
            // ship the page with only the safe bridge-derived rows first, then
            // opt into historical — and disable JUST the historical part instantly
            // if anything looks wrong, without hiding the whole page.
            'show_client_issued_historical' => [
                'FriendlyName' => 'Also show OLD (pre-bridge) documents',
                'Type' => 'yesno',
                'Description' => 'On the «Εκδοθέντα Παραστατικά» page, ALSO list the client\'s pre-bridge (imported) παραστατικά, matched by the deterministic invoices.whmcs_invoice_id link to their OWN WHMCS invoices (never by ΑΦΜ — no third-party leak). OFF by default: only bridge-issued documents show until you flip this on.',
            ],
        ],
    ];
}

function ekdosi_bridge_activate(): array
{
    // All the schema work (create our tables, restore tblinvoices.invoiced to
    // SMALLINT) lives in SchemaGuard so it can ALSO self-heal on every admin
    // page load (see ekdosi_bridge_output) — meaning a future schema change
    // needs only a file upload, NOT a deactivate/reactivate (which WHMCS punishes
    // by wiping the bridge's saved settings). Activation just runs the same
    // idempotent steps eagerly and reports their notes.
    $notes = SchemaGuard::ensure();

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
    // Self-heal the schema on every admin page load (idempotent + cheap +
    // privilege-safe). This is what lets a schema change ship as a plain file
    // upload — no deactivate/reactivate, so WHMCS never wipes the saved bridge
    // settings.
    SchemaGuard::ensureSilently();

    $action = isset($_REQUEST['action']) ? (string) $_REQUEST['action'] : '';
    $dispatcher = new AdminDispatcher;
    echo $dispatcher->dispatch($action, $vars);
}

/**
 * Client-area entry for the addon. One WHMCS module has a SINGLE
 * {module}_clientarea function, so it routes on `act`:
 *   - act=issued / act=pdf → the "Εκδοθέντα Παραστατικά" page (Gate::issuedVisibleTo),
 *   - anything else        → the "Παραστατικά σε τρίτους (v2)" page (Gate::visibleTo).
 * Each branch enforces its OWN gate HERE (not just on the navbar link), so a
 * hidden page can't be reached by URL-guessing; the two switches are independent.
 */
function ekdosi_bridge_clientarea($vars): array
{
    $clientId = (int) ($_SESSION['uid'] ?? 0);
    $act = isset($_REQUEST['act']) ? (string) $_REQUEST['act'] : '';

    // "Εκδοθέντα Παραστατικά" — the customer's issued-documents list + PDF proxy.
    if ($act === 'issued' || $act === 'pdf') {
        if (! Gate::issuedVisibleTo($clientId)) {
            return [
                'pagetitle' => 'Εκδοθέντα Παραστατικά',
                'breadcrumb' => ['index.php?m=ekdosi_bridge&act=issued' => 'Εκδοθέντα Παραστατικά'],
                'templatefile' => 'issuedpage',
                'requirelogin' => true,
                'vars' => ['pagecontent' => '<div class="alert alert-info">Η σελίδα δεν είναι διαθέσιμη.</div>'],
            ];
        }

        $issued = new IssuedController;
        if ($act === 'pdf') {
            // Streams the PDF and exits on success; only returns (an error
            // string) when it could NOT stream — shown on the page below.
            $html = $issued->streamPdf($vars, $clientId, (int) ($_GET['doc'] ?? 0));
        } else {
            $html = $issued->render($vars, $clientId);
        }

        return [
            'pagetitle' => 'Εκδοθέντα Παραστατικά',
            'breadcrumb' => ['index.php?m=ekdosi_bridge&act=issued' => 'Εκδοθέντα Παραστατικά'],
            'templatefile' => 'issuedpage',
            'requirelogin' => true,
            'vars' => ['pagecontent' => $html],
        ];
    }

    // Default: the "Παραστατικά σε τρίτους (v2)" third-party routing editor.
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
