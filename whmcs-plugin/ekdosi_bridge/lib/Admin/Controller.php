<?php

namespace WHMCS\Module\Addon\EkdosiBridge\Admin;

use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\EkdosiBridge\EkdosiClient;
use WHMCS\Module\Addon\EkdosiBridge\ThirdPartyStore;

/**
 * Admin module page: configure + smoke-test + manual push.
 *
 * Pages:
 *   - index: search box (paste an invoice id, jump to its detail)
 *   - show:  per-invoice detail with current status + action buttons
 *   - push:  "Send to ekdosi for review" — POSTs to ekdosi's
 *            webhook with HMAC signature
 *   - status: "Show ekdosi status" — GETs from ekdosi's status
 *             endpoint and renders the response
 *   - reset: legacy "set invoiced=0" — kept for the rare rollback
 *            workflow (e.g. operator cancelled an invoice at AADE
 *            and needs WHMCS to forget about it)
 */
class Controller
{
    public function index(array $vars): string
    {
        $link = htmlspecialchars($vars['modulelink']);
        $token = $this->csrfField();

        // Third-party (timologia v2) status: own tables present + row counts.
        $tpStatus = '<span class="label label-default">tables not created — activate or sync</span>';
        if (ThirdPartyStore::hasOwnTables()) {
            $contacts = (int) Capsule::table(ThirdPartyStore::CONTACTS)->count();
            $routes = (int) Capsule::table(ThirdPartyStore::ROUTING)->count();
            $tpStatus = '<span class="label label-success">ready</span> '
                .htmlspecialchars((string) $contacts).' contacts, '
                .htmlspecialchars((string) $routes).' routing rows';
        }
        $legacyNote = ThirdPartyStore::hasLegacyTables()
            ? 'Legacy mod_timologia tables detected — syncable.'
            : 'No legacy mod_timologia tables found on this WHMCS.';

        return <<<EOF
<h2>Ekdosi Bridge</h2>
<p class="text-muted">Paste a WHMCS invoice id below to inspect / push / reset.</p>
<form action="{$link}&action=show" method="POST">
    <div class="form-inline">
        <input class="form-control" name="invoiceid" placeholder="Invoice ID (e.g. 12345)" type="text" required>
        <button class="btn btn-primary" type="submit">Inspect</button>
    </div>
</form>
<hr>
<h3>Παραστατικά σε τρίτους (timologia v2)</h3>
<p>Own routing tables: {$tpStatus}</p>
<p class="text-muted">{$legacyNote}</p>
<form action="{$link}&action=sync" method="POST" style="display:inline-block;">
    {$token}
    <button class="btn btn-default" type="submit"
        onclick="return confirm('Import third-party contacts + routing from the legacy mod_timologia tables into the bridge\'s own tables? Re-runnable and idempotent; legacy tables are only read.');">
        <i class="fa fa-download"></i> Sync from legacy timologia
    </button>
</form>
EOF;
    }

    /**
     * T-1b-2: import the legacy mod_timologia* routing into the bridge's own
     * tables (re-runnable, idempotent). Read-only against the legacy tables.
     */
    public function sync(array $vars): string
    {
        $link = htmlspecialchars($vars['modulelink']);
        if (! $this->csrfValid()) {
            return $this->errorPage($link, 'Security token mismatch. Go back and retry.');
        }

        $r = ThirdPartyStore::syncFromLegacy();
        if (empty($r['ok'])) {
            $this->logActivity('EkdosiBridge: timologia sync skipped — '.($r['message'] ?? 'unknown'));

            return $this->errorPage($link, $r['message'] ?? 'Sync could not run.');
        }

        $this->logActivity(sprintf(
            'EkdosiBridge: timologia sync — contacts +%d/~%d, routes +%d/~%d, %d skipped.',
            $r['contacts_inserted'], $r['contacts_updated'],
            $r['routes_inserted'], $r['routes_updated'], $r['routes_skipped'],
        ));

        $ci = (int) $r['contacts_inserted'];
        $cu = (int) $r['contacts_updated'];
        $ri = (int) $r['routes_inserted'];
        $ru = (int) $r['routes_updated'];
        $rs = (int) $r['routes_skipped'];

        return <<<EOF
<p><a class="btn btn-default" href="{$link}">&larr; Back</a></p>
<h2>Sync from legacy timologia</h2>
<div class="alert alert-success">Done.</div>
<table class="table">
    <tr><th>Contacts inserted</th><td>{$ci}</td></tr>
    <tr><th>Contacts updated</th><td>{$cu}</td></tr>
    <tr><th>Routing inserted</th><td>{$ri}</td></tr>
    <tr><th>Routing updated</th><td>{$ru}</td></tr>
    <tr><th>Routing skipped (orphan contact)</th><td>{$rs}</td></tr>
</table>
<p class="text-muted">Re-run any time the legacy data changes. Rows created on the v2 side (no legacy id) are never touched.</p>
EOF;
    }

    public function show(array $vars): string
    {
        $link = htmlspecialchars($vars['modulelink']);
        $invoiceId = (int) ($_POST['invoiceid'] ?? $_GET['invoiceid'] ?? 0);
        if ($invoiceId <= 0) {
            return $this->errorPage($link, 'Invalid invoice id.');
        }

        $invoice = Capsule::table('tblinvoices')->find($invoiceId);
        if (! $invoice) {
            return $this->errorPage($link, "Invoice #{$invoiceId} not found in tblinvoices.");
        }

        $invoiced = (int) ($invoice->invoiced ?? 0);
        $invoicedLabel = $invoiced === 0
            ? '<span class="label label-default">not filed yet</span>'
            : '<span class="label label-success">filed (MARK '.htmlspecialchars((string) $invoiced).')</span>';

        // Pull live status from ekdosi.
        $client = EkdosiClient::fromConfig();
        $statusBlock = '<p class="text-muted">Ekdosi status: <em>not queried</em></p>';
        if ($client !== null) {
            $statusResp = $client->getInvoiceStatus($invoiceId);
            $statusBlock = $this->renderStatusBlock($statusResp);
        } else {
            $statusBlock = '<div class="alert alert-warning">'
                .'Bridge not configured: set base URL / slug / secret on the module config page.</div>';
        }

        // CSRF token: WHMCS's generate_token('plain') emits a hidden
        // <input name="token">; check_token() in the handlers below
        // validates it. Without this, a logged-in admin could be
        // tricked (CSRF) into pushing or resetting arbitrary invoices.
        $token = $this->csrfField();
        $actions = '<form action="'.$link.'&action=push" method="POST" style="display:inline-block; margin-right:8px;">'
            .$token
            .'<input type="hidden" name="invoiceid" value="'.$invoiceId.'">'
            .'<button class="btn btn-primary" type="submit">Send to ekdosi for review</button>'
            .'</form>'
            .'<form action="'.$link.'&action=reset" method="POST" style="display:inline-block;">'
            .$token
            .'<input type="hidden" name="invoiceid" value="'.$invoiceId.'">'
            .'<button class="btn btn-warning" type="submit" '
            .'onclick="return confirm(\'Set tblinvoices.invoiced = 0 for invoice #'.$invoiceId.'? '
            .'Use this only after cancelling the AADE filing first.\');">Reset to unfiled</button>'
            .'</form>';

        return <<<EOF
<p><a class="btn btn-default" href="{$link}">&larr; Back</a></p>
<h2>Invoice #{$invoiceId}</h2>
<p>tblinvoices.invoiced: {$invoicedLabel}</p>
{$statusBlock}
<hr>
<p>{$actions}</p>
EOF;
    }

    public function push(array $vars): string
    {
        $link = htmlspecialchars($vars['modulelink']);
        if (! $this->csrfValid()) {
            return $this->errorPage($link, 'Security token mismatch. Go back and retry.');
        }
        $invoiceId = (int) ($_POST['invoiceid'] ?? 0);
        if ($invoiceId <= 0) {
            return $this->errorPage($link, 'Invalid invoice id.');
        }
        $client = EkdosiClient::fromConfig();
        if ($client === null) {
            return $this->errorPage($link, 'Bridge not configured. Set base URL / slug / secret first.');
        }

        $result = $client->pushInvoicePaid($invoiceId);
        $this->logActivity("EkdosiBridge: pushed invoice #{$invoiceId} to ekdosi (http={$result['http_status']}).");

        $css = $result['ok'] ? 'success' : 'danger';
        $body = htmlspecialchars((string) $result['body']);
        $msg = htmlspecialchars((string) $result['summary']);
        $showLink = $link.'&action=show&invoiceid='.$invoiceId;

        return <<<EOF
<p><a class="btn btn-default" href="{$link}">&larr; Back</a></p>
<h2>Push to ekdosi: #{$invoiceId}</h2>
<div class="alert alert-{$css}">{$msg}</div>
<pre style="white-space: pre-wrap;">{$body}</pre>
<p><a class="btn btn-primary" href="{$showLink}">Refresh status</a></p>
EOF;
    }

    public function reset(array $vars): string
    {
        $link = htmlspecialchars($vars['modulelink']);
        if (! $this->csrfValid()) {
            return $this->errorPage($link, 'Security token mismatch. Go back and retry.');
        }
        $invoiceId = (int) ($_POST['invoiceid'] ?? 0);
        if ($invoiceId <= 0) {
            return $this->errorPage($link, 'Invalid invoice id.');
        }
        Capsule::table('tblinvoices')->where('id', $invoiceId)->update(['invoiced' => 0]);
        $this->logActivity("EkdosiBridge: reset tblinvoices.invoiced=0 for invoice #{$invoiceId}.");
        $showLink = $link.'&action=show&invoiceid='.$invoiceId;
        return <<<EOF
<p><a class="btn btn-default" href="{$link}">&larr; Back</a></p>
<div class="alert alert-success">Invoice #{$invoiceId} reset: tblinvoices.invoiced = 0.</div>
<p><a class="btn btn-primary" href="{$showLink}">Back to invoice</a></p>
EOF;
    }

    private function renderStatusBlock(array $resp): string
    {
        if (! ($resp['ok'] ?? false)) {
            $msg = htmlspecialchars((string) ($resp['summary'] ?? 'Unknown ekdosi error.'));
            return '<div class="alert alert-warning">Ekdosi status query failed: '.$msg.'</div>';
        }
        $data = $resp['data'] ?? [];
        if (! ($data['found'] ?? false)) {
            return '<div class="alert alert-info">Ekdosi has no row for this invoice yet — push it for review.</div>';
        }
        $status = htmlspecialchars((string) ($data['status'] ?? '?'));
        $mark = $data['mydata_mark'] ?? null;
        $notes = htmlspecialchars((string) ($data['notes'] ?? ''));
        $rejected = htmlspecialchars((string) ($data['rejected_reason'] ?? ''));
        $markRow = $mark ? '<li>MARK: <code>'.htmlspecialchars((string) $mark).'</code></li>' : '';
        $rejRow = $rejected !== '' ? '<li>Rejected reason: '.$rejected.'</li>' : '';
        return <<<EOF
<div class="alert alert-info">
<strong>Ekdosi status: {$status}</strong>
<ul>
    {$markRow}
    {$rejRow}
    <li>Notes: {$notes}</li>
</ul>
</div>
EOF;
    }

    private function errorPage(string $link, string $msg): string
    {
        $safe = htmlspecialchars($msg);
        return <<<EOF
<div class="alert alert-warning">{$safe}</div>
<p><a class="btn btn-default" href="{$link}">&larr; Back</a></p>
EOF;
    }

    private function logActivity(string $message): void
    {
        if (! function_exists('logActivity')) {
            return;
        }
        $currentUser = new \WHMCS\Authentication\CurrentUser();
        $user = $currentUser->user();
        logActivity($message, $user ? $user->id : 0);
    }

    /**
     * Hidden CSRF token field for state-changing forms. WHMCS's
     * generate_token('plain') returns the <input type="hidden"
     * name="token" ...> HTML. Guarded with function_exists so the
     * plugin degrades to "no field" rather than fataling on a WHMCS
     * build that lacks the helper (very old installs); csrfValid()
     * mirrors the same tolerance.
     */
    private function csrfField(): string
    {
        if (function_exists('generate_token')) {
            return (string) generate_token('plain');
        }
        return '';
    }

    /**
     * Validate the CSRF token on a state-changing POST. We compare the
     * posted `token` against the value WHMCS stores in the session —
     * the SAME value generate_token('plain') embedded in our form.
     *
     * WHMCS keeps the admin CSRF token in $_SESSION['token']. The earlier
     * version of this method compared against $_SESSION['tokenval'], which
     * WHMCS never sets → the expected value was always '' → EVERY
     * state-changing action (sync / push / reset) failed with
     * "Security token mismatch", regardless of a correct form token. Read
     * the canonical key (with a 'tokenval' fallback for any odd build).
     *
     * Tolerance: a (very old) WHMCS without generate_token() emits no token
     * field at all, so we don't hard-block there — matching csrfField().
     */
    private function csrfValid(): bool
    {
        // No token helper at all → legacy build, don't hard-block (csrfField
        // also degrades to an empty field there).
        if (! function_exists('generate_token')) {
            return true;
        }

        $sent = (string) ($_POST['token'] ?? '');
        $expected = (string) ($_SESSION['token'] ?? ($_SESSION['tokenval'] ?? ''));

        return $sent !== '' && $expected !== '' && hash_equals($expected, $sent);
    }
}
