<?php

/**
 * Per-invoice admin sidebar buttons.
 *
 * The `AdminInvoicesControlsOutput` hook fires when WHMCS renders an
 * admin invoice view page; the string we return is appended to the
 * action-buttons sidebar. Three buttons:
 *
 *   - "Send to ekdosi" — POSTs the invoice id to ekdosi's webhook
 *     so the row appears in the inbox for operator review.
 *
 *   - "Ekdosi status"  — opens the bridge's admin module page
 *     (?action=show) for this invoice id; that page calls the
 *     status endpoint and renders the full state.
 *
 *   - "Reset to unfiled" — the legacy rollback workflow (rare).
 *
 * The buttons render as plain links into the bridge addon's admin
 * page, NOT as inline AJAX. This keeps the WHMCS-side code tiny
 * (no JavaScript, no CSRF token plumbing) at the cost of one
 * page-load per action. For the volume of invoice-issue events
 * we expect, the simplicity wins.
 */

use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\EkdosiBridge\Client\Gate;
use WHMCS\View\Menu\Item as MenuItem;

if (! defined('WHMCS')) {
    exit('This file cannot be accessed directly');
}

require_once __DIR__.'/lib/ThirdPartyStore.php';
require_once __DIR__.'/lib/Client/Gate.php';

/**
 * T-2: add the client-area "Παραστατικά σε τρίτους (v2)" menu item — but ONLY
 * when the admin switch is on and (if a pilot allowlist is set) this client is
 * on it. Default OFF, so customers see nothing. Distinct "(v2)" label so it
 * doesn't get confused with the legacy timologia plugin's link during the
 * parallel run.
 */
add_hook('ClientAreaPrimaryNavbar', 50, function (MenuItem $primaryNavbar) {
    $clientId = (int) ($_SESSION['uid'] ?? 0);
    if (! Gate::visibleTo($clientId)) {
        return;
    }
    $billing = $primaryNavbar->getChild('Billing');
    $parent = $billing ?? $primaryNavbar;
    $parent->addChild('ekdosi_timologia_v2', [
        'label' => 'Παραστατικά σε τρίτους (v2)',
        'uri' => 'index.php?m=ekdosi_bridge',
        'order' => 100,
    ]);
});

add_hook('AdminInvoicesControlsOutput', 1, function ($vars) {
    $invoiceId = (int) ($vars['invoiceid'] ?? 0);
    if ($invoiceId <= 0) {
        return '';
    }

    // The link points at the bridge addon's module page with the
    // invoice id pre-filled. WHMCS's standard admin URL for an
    // addon module page is /admin/addonmodules.php?module=...
    $baseLink = '/admin/addonmodules.php?module=ekdosi_bridge';
    $showLink = htmlspecialchars($baseLink.'&action=show&invoiceid='.$invoiceId);

    // At-a-glance AADE state, straight from tblinvoices.invoiced (the bridge
    // writes the MARK back there). No ekdosi call — just the local column:
    //   0/null = not filed · 1 = legacy prepare_for_ekdosi "filed" flag ·
    //   a long number = a real AADE MARK.
    $badge = '<span class="label label-default">Όχι στο AADE</span>';
    try {
        $invoiced = (string) (Capsule::table('tblinvoices')->where('id', $invoiceId)->value('invoiced') ?? '0');
        if ($invoiced !== '' && $invoiced !== '0') {
            if (strlen($invoiced) >= 10) {
                $badge = '<span class="label label-success" title="MARK">Στο AADE · ΜΑΡΚ '
                    .htmlspecialchars($invoiced).'</span>';
            } else {
                // Short non-zero value = the legacy {0,1} flag, not a MARK.
                $badge = '<span class="label label-info" title="legacy prepare_for_ekdosi flag">Σημειωμένο (legacy)</span>';
            }
        }
    } catch (Throwable $e) {
        $badge = '<span class="label label-warning">κατάσταση μη διαθέσιμη</span>';
    }

    return <<<EOF
<div class="form-group">
    <label>Ekdosi / myDATA</label>
    <div>
        <div style="margin-bottom:6px">{$badge}</div>
        <a href="{$showLink}" class="btn btn-default btn-sm" title="Show ekdosi status / push / reset for this invoice">
            <i class="fa fa-external-link"></i> Open in Ekdosi Bridge
        </a>
    </div>
</div>
EOF;
});
