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

/**
 * Visibility: a link on the admin client profile to the per-client 3-way
 * mapping page (WHMCS # → ekdosi παραστατικό → ΜΑΡΚ, drafts included). Uses the
 * supported AdminClientProfileTabFields hook (renders an extra field row);
 * keeps the heavy table on the addon's own page (full HTML control) rather
 * than fighting WHMCS's profile-field escaping.
 */
add_hook('AdminClientProfileTabFields', 1, function ($vars) {
    $clientId = (int) ($vars['userid'] ?? $vars['id'] ?? 0);
    if ($clientId <= 0) {
        return [];
    }
    $url = htmlspecialchars('addonmodules.php?module=ekdosi_bridge&action=client&userid='.$clientId);

    return [
        'Ekdosi / ΑΑΔΕ' => '<a href="'.$url.'" class="btn btn-default btn-sm">'
            .'<i class="fa fa-file-text-o"></i> Παραστατικά ekdosi (WHMCS→ΜΑΡΚ)</a>',
    ];
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

    // "Αποστολή στο Ekdosi" — POST (CSRF) to the addon's push action, which
    // sends {whmcs_invoice_id} to ekdosi's webhook → the invoice appears in the
    // operator's inbox for review/filing. Lets staff push a SPECIFIC invoice on
    // demand (e.g. a customer who just supplied their ΑΦΜ) without waiting for
    // the scheduled fetch. Mirrors Admin\Controller::csrfField (raw token →
    // hidden input; csrfValid compares $_POST['token'] to it).
    $tokenRaw = function_exists('generate_token') ? (string) generate_token('plain') : '';
    $tokenField = $tokenRaw === ''
        ? ''
        : (stripos($tokenRaw, '<input') !== false
            ? $tokenRaw
            : '<input type="hidden" name="token" value="'.htmlspecialchars($tokenRaw, ENT_QUOTES).'">');
    $pushAction = htmlspecialchars($baseLink.'&action=push');

    return <<<EOF
<div class="form-group">
    <label>Ekdosi / myDATA</label>
    <div>
        <div style="margin-bottom:6px">{$badge}</div>
        <form method="post" action="{$pushAction}" style="display:inline-block; margin-bottom:6px">
            {$tokenField}
            <input type="hidden" name="invoiceid" value="{$invoiceId}">
            <button type="submit" class="btn btn-primary btn-sm" title="Στείλε αυτό το τιμολόγιο στο inbox του ekdosi για έλεγχο/έκδοση">
                <i class="fa fa-paper-plane"></i> Αποστολή στο Ekdosi
            </button>
        </form>
        <a href="{$showLink}" class="btn btn-default btn-sm" title="Δες κατάσταση ekdosi / push / reset γι' αυτό το τιμολόγιο">
            <i class="fa fa-external-link"></i> Άνοιγμα στο Ekdosi Bridge
        </a>
    </div>
</div>
EOF;
});

/**
 * #1 — AADE/MARK badge on the admin INVOICE LIST (Billing → Invoices). WHMCS
 * has no per-row hook for that table, so we inject a tiny script (footer hook,
 * fires on every admin page but self-gates to invoices.php list) that:
 *   - reads the invoice ids from the per-row "edit" links,
 *   - fetches their tblinvoices.invoiced via the addon's read-only `marks`
 *     JSON action (same-origin, admin-authed),
 *   - appends a badge next to each invoice link (green "ΑΑΔΕ ✓" + MARK tooltip
 *     when filed, "—" when not, "legacy" for the old {0,1} flag).
 *
 * Best-effort DOM decoration — defensive (try/catch, feature-detects fetch,
 * de-dupes). The link selector mirrors WHMCS's standard invoice-list markup;
 * if a theme/version changes it, only the badge is missing (nothing breaks).
 */
add_hook('AdminAreaFooterOutput', 1, function ($vars) {
    return <<<'HTML'
<script>
(function () {
  try {
    if (location.pathname.indexOf('invoices.php') === -1) return;
    if (/[?&]action=/.test(location.search)) return; // single-invoice view/edit, not the list
    if (!window.fetch) return;

    var links = [].slice.call(document.querySelectorAll('a[href*="invoices.php?action=edit&id="]'));
    var byId = {};
    links.forEach(function (a) {
      var m = a.href.match(/[?&]id=(\d+)/);
      if (m) { (byId[m[1]] = byId[m[1]] || []).push(a); }
    });
    var ids = Object.keys(byId);
    if (!ids.length) return;

    fetch('addonmodules.php?module=ekdosi_bridge&action=marks&ids=' + ids.join(','), { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (map) {
        ids.forEach(function (id) {
          var v = String(map[id] == null ? '0' : map[id]);
          byId[id].forEach(function (a) {
            if (a.parentNode.querySelector('.ekdosi-aade-badge')) return;
            var b = document.createElement('span');
            b.className = 'ekdosi-aade-badge label';
            b.style.marginLeft = '6px';
            b.style.fontSize = '11px';
            if (v.length >= 10) { b.className += ' label-success'; b.title = 'ΜΑΡΚ ' + v; b.textContent = 'ΑΑΔΕ ✓'; }
            else if (v !== '0' && v !== '') { b.className += ' label-info'; b.title = 'legacy flag'; b.textContent = 'legacy'; }
            else { b.className += ' label-default'; b.title = 'Δεν έχει υποβληθεί στην ΑΑΔΕ'; b.textContent = '—'; }
            a.parentNode.insertBefore(b, a.nextSibling);
          });
        });
      })
      .catch(function () {});
  } catch (e) {}
})();
</script>
HTML;
});
