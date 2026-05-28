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

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

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

    return <<<EOF
<div class="form-group">
    <label>Ekdosi</label>
    <div>
        <a href="{$showLink}" class="btn btn-default btn-sm" title="Show ekdosi status / push / reset for this invoice">
            <i class="fa fa-external-link"></i> Open in Ekdosi Bridge
        </a>
    </div>
</div>
EOF;
});
