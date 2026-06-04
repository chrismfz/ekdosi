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
use WHMCS\Module\Addon\EkdosiBridge\InvoiceMarkStore;
use WHMCS\Module\Addon\EkdosiBridge\RelidInspector;
use WHMCS\View\Menu\Item as MenuItem;

if (! defined('WHMCS')) {
    exit('This file cannot be accessed directly');
}

require_once __DIR__.'/lib/InvoiceMarkStore.php';
require_once __DIR__.'/lib/ThirdPartyStore.php';
require_once __DIR__.'/lib/RelidInspector.php';
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
    // invoice id pre-filled. RELATIVE on purpose: this output renders
    // INSIDE a WHMCS admin page (invoices.php), so 'addonmodules.php?...'
    // resolves against the real admin directory — which is operator-
    // configurable (e.g. /clients/sysadmin/, not the default /admin/).
    // Hardcoding '/admin/' 404s on a custom admin folder; the other hooks
    // (client profile tab, footer marks JS) already use a relative path.
    $baseLink = 'addonmodules.php?module=ekdosi_bridge';
    $showLink = htmlspecialchars($baseLink.'&action=show&invoiceid='.$invoiceId);

    // At-a-glance state — TWO independent signals during the dual-run
    // ("test new, keep invoicing from old"):
    //   1. our AADE MARK from mod_ekdosi_invoice_marks (ekdosi filed it), and
    //   2. the legacy `tblinvoices.invoiced` flag, READ-ONLY — "Τιμολογήθηκε
    //      στη legacy" (!= 0). We never WRITE invoiced anymore.
    // The authoritative answer for historical invoices lives on the client's
    // Ekdosi card (matched by ΑΦΜ) — linked below.
    $userId = (int) ($vars['userid'] ?? 0);
    $badge = '<span class="label label-default" title="Καμία επιστροφή ΜΑΡΚ μέσω WHMCS">Όχι στο AADE μέσω WHMCS</span>';
    $legacyBadge = '';
    try {
        $mark = InvoiceMarkStore::get($invoiceId);
        $invcode = InvoiceMarkStore::invcodeFor($invoiceId);
        $state = InvoiceMarkStore::stateFor($invoiceId);
        $invoiced = (int) (Capsule::table('tblinvoices')->where('id', $invoiceId)->value('invoiced') ?? 0);
        if ($userId <= 0) {
            $userId = (int) (Capsule::table('tblinvoices')->where('id', $invoiceId)->value('userid') ?? 0);
        }
        if ($mark !== null && $mark !== '') {
            $tpy = ($invcode !== null && $invcode !== '')
                ? 'ΤΠΥ '.htmlspecialchars($invcode).' · '
                : '';
            if ($state === 'cancelled') {
                // Filed THEN cancelled at AADE — keep the MARK for audit but make
                // the badge unmistakably "no longer valid".
                $badge = '<span class="label label-danger" title="ekdosi / AADE — ακυρωμένο">Στο AADE · '
                    .$tpy.'ΜΑΡΚ '.htmlspecialchars($mark).' · ΑΚΥΡΩΜΕΝΟ</span>';
            } else {
                $badge = '<span class="label label-success" title="ekdosi / AADE">Στο AADE · '
                    .$tpy.'ΜΑΡΚ '.htmlspecialchars($mark).'</span>';
            }
        }
        if ($invoiced !== 0) {
            $legacyBadge = ' <span class="label label-info" title="tblinvoices.invoiced != 0">Τιμολογήθηκε στη legacy</span>';
        }
    } catch (Throwable $e) {
        $badge = '<span class="label label-warning">κατάσταση μη διαθέσιμη</span>';
    }
    $badge .= $legacyBadge;

    // Link to the client's full Ekdosi card (WHMCS#→παραστατικό live rows +
    // ΑΦΜ-matched historical ΤΠΥ/ΜΑΡΚ). This is where an imported invoice that
    // has NO WHMCS-side MARK still shows up — matched by the client's ΑΦΜ.
    $clientCardLink = $userId > 0
        ? '<a href="'.htmlspecialchars($baseLink.'&action=client&userid='.$userId).'" '
            .'class="btn btn-default btn-sm" title="Δες όλα τα παραστατικά ekdosi αυτού του πελάτη (αντιστοίχιση ΑΦΜ)">'
            .'<i class="fa fa-file-text-o"></i> Παραστατικά πελάτη στο Ekdosi</a>'
        : '';

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

    // relid visibility — at a glance, how many lines WHMCS would (re)renew when
    // this invoice is marked PAID (relid > 0), and whether any are ALREADY
    // renewed (next due in the future = the double-renewal trap). The full
    // per-line table + «Μηδενισμός relid» live on the addon page (relidCheck),
    // mirroring the «Άνοιγμα στο Ekdosi Bridge» pattern.
    $relidBlock = '';
    try {
        $relidItems = RelidInspector::items($invoiceId);
        $relidActive = RelidInspector::activeCount($relidItems);
        $relidRenewed = RelidInspector::alreadyRenewedCount($relidItems);
        $relidLink = htmlspecialchars($baseLink.'&action=show&invoiceid='.$invoiceId);
        if ($relidActive > 0) {
            $cls = $relidRenewed > 0 ? 'danger' : 'warning';
            $extra = $relidRenewed > 0 ? ' — '.$relidRenewed.' ήδη ανανεωμένες!' : '';
            $relidBlock = <<<EOF
<div class="form-group">
    <label>relid (αυτόματη ανανέωση WHMCS)</label>
    <div>
        <div style="margin-bottom:6px"><span class="label label-{$cls}" title="Με Mark Paid το WHMCS θα (ξανα)ανανεώσει αυτές τις γραμμές">⚠ {$relidActive} γραμμές με relid{$extra}</span></div>
        <a href="{$relidLink}" class="btn btn-default btn-sm" title="Δες/μηδένισε το relid ανά γραμμή ώστε να μην γίνει διπλή ανανέωση στο Mark Paid">
            <i class="fa fa-list-ol"></i> Έλεγχος relid
        </a>
    </div>
</div>
EOF;
        } else {
            $relidBlock = <<<EOF
<div class="form-group">
    <label>relid (αυτόματη ανανέωση WHMCS)</label>
    <div>
        <span class="label label-success" title="Καμία γραμμή με ενεργό relid">Καθαρό</span>
        <a href="{$relidLink}" class="btn btn-link btn-sm">λεπτομέρειες</a>
    </div>
</div>
EOF;
        }
    } catch (Throwable $e) {
        $relidBlock = '';
    }

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
        {$clientCardLink}
    </div>
</div>
{$relidBlock}
EOF;
});

/**
 * #1 — AADE/MARK badge on the admin INVOICE LIST (Billing → Invoices). WHMCS
 * has no per-row hook for that table, so we inject a tiny script (footer hook,
 * fires on every admin page but self-gates to invoices.php list) that:
 *   - reads the invoice ids from the per-row "edit" links,
 *   - fetches their ekdosi MARK via the addon's read-only `marks`
 *     JSON action (from mod_ekdosi_invoice_marks; same-origin, admin-authed),
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
            // Only two honest states on the WHMCS side: a real AADE MARK
            // (>=10 digits, written back by the bridge) or nothing. The old
            // {0,1} prepare_for_ekdosi flag is dropped — it conflated "we
            // touched this" with "filed", reading as a misleading "legacy"
            // badge on the 16k imported invoices ekdosi already knows about.
            if (v.length >= 10) { b.className += ' label-success'; b.title = 'ΜΑΡΚ ' + v; b.textContent = 'ΑΑΔΕ ✓'; }
            else { b.className += ' label-default'; b.title = 'Δεν έχει υποβληθεί στην ΑΑΔΕ μέσω WHMCS'; b.textContent = '—'; }
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

/**
 * Q1 UX — kill the WHMCS 8.9+ "view-only + Manage Invoice" extra click on the
 * admin invoice LIST.
 *
 * Since 8.9 the admin invoice list opens an invoice in a VIEW-ONLY page; you
 * then have to click "Manage Invoice" to reach the editable page. That editable
 * page (legacy `invoices.php?action=edit&id=N`) is ALSO the ONLY place our
 * `AdminInvoicesControlsOutput` buttons render — that hook does NOT fire on the
 * view-only page nor on the new `admin/billing/invoices/N` URL. So the extra
 * hop hides BOTH WHMCS's own management actions AND our ekdosi/relid buttons.
 *
 * WHMCS ships no setting to default the list to edit mode (the supported path
 * is the per-row "Edit" link). This footer script — LIST page only — repoints
 * each row's invoice link straight at the legacy edit URL, so a single click
 * lands on the editable page with our buttons present.
 *
 * Defensive by design: it ONLY rewrites anchors whose href matches a KNOWN
 * view-only shape (the new `/billing/invoices/N` path, or the legacy
 * `invoices.php?action=view|manage&id=N`). Any other markup is left untouched,
 * so on a theme/version we don't recognise it silently no-ops and the native
 * per-row "Edit" link remains the fallback. All in try/catch.
 */
add_hook('AdminAreaFooterOutput', 1, function ($vars) {
    return <<<'HTML'
<script>
(function () {
  try {
    if (location.pathname.indexOf('invoices.php') === -1) return;
    if (/[?&]action=/.test(location.search)) return; // the LIST only, not a single-invoice view

    var anchors = [].slice.call(document.querySelectorAll('a[href]'));
    anchors.forEach(function (a) {
      var href = a.getAttribute('href') || '';
      // new view-only URL: .../billing/invoices/1234  | legacy view-only: invoices.php?action=view|manage&id=1234
      var m = href.match(/\/billing\/invoices\/(\d+)(?:[\/?#]|$)/)
           || href.match(/invoices\.php\?action=(?:view|manage)&id=(\d+)/);
      if (!m) return;
      a.setAttribute('href', 'invoices.php?action=edit&id=' + m[1]);
      if (!a.getAttribute('title')) {
        a.setAttribute('title', 'Άνοιγμα σε επεξεργασία (χωρίς το ενδιάμεσο view-only· εμφανίζει τα κουμπιά ekdosi)');
      }
    });
  } catch (e) {}
})();
</script>
HTML;
});
