<?php

namespace WHMCS\Module\Addon\EkdosiBridge\Admin;

use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\EkdosiBridge\EkdosiClient;

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
        return <<<EOF
<h2>Ekdosi Bridge</h2>
<p class="text-muted">Paste a WHMCS invoice id below to inspect / push / reset.</p>
<form action="{$link}&action=show" method="POST">
    <div class="form-inline">
        <input class="form-control" name="invoiceid" placeholder="Invoice ID (e.g. 12345)" type="text" required>
        <button class="btn btn-primary" type="submit">Inspect</button>
    </div>
</form>
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

        $actions = '<form action="'.$link.'&action=push" method="POST" style="display:inline-block; margin-right:8px;">'
            .'<input type="hidden" name="invoiceid" value="'.$invoiceId.'">'
            .'<button class="btn btn-primary" type="submit">Send to ekdosi for review</button>'
            .'</form>'
            .'<form action="'.$link.'&action=reset" method="POST" style="display:inline-block;">'
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
}
