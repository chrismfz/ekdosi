<?php

namespace WHMCS\Module\Addon\EkdosiBridge\Client;

use Throwable;
use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\EkdosiBridge\EkdosiClient;

/**
 * Client-area page "Εκδοθέντα Παραστατικά": the reseller sees the ekdosi
 * παραστατικά that were issued for them through the bridge — their OWN documents
 * and the ones routed to third parties they set up in «Παραστατικά σε τρίτους
 * (v2)» (decision (B): they entered the party's data and bought on its behalf,
 * so they already hold it).
 *
 * The list is fetched LIVE from ekdosi (the authority: a split maps one WHMCS
 * invoice to many παραστατικά, which the 1:1 WHMCS-side mark store can't hold).
 * ekdosi scopes strictly to this client's WHMCS invoices, so a client only ever
 * sees documents produced from invoices THEY paid — never a third party's
 * unrelated invoices.
 *
 * The PDF is PROXIED (act=pdf): the plugin fetches the bytes server-side over
 * the HMAC channel and streams them, so the signed ekdosi URL never reaches the
 * browser. `verify_url` (AADE/πάροχος public verification link) is shown as a
 * plain link — it is a public, shareable URL by nature.
 *
 * Everything is scoped to the logged-in client id (passed in, never taken from
 * the request); the page handler enforces Gate::issuedVisibleTo before
 * dispatching here.
 */
class IssuedController
{
    public function render(array $vars, int $clientId): string
    {
        $link = htmlspecialchars($vars['modulelink'] ?? 'index.php?m=ekdosi_bridge');

        $client = EkdosiClient::fromConfig();
        if ($client === null) {
            return $this->alert('info', 'Η υπηρεσία δεν είναι διαθέσιμη αυτή τη στιγμή.');
        }

        // The client's OWN WHMCS invoice ids (tblinvoices.userid) — the leak-proof
        // boundary ekdosi uses to also surface PRE-BRIDGE historical παραστατικά
        // via the deterministic invoices.whmcs_invoice_id FK. Only gathered/sent
        // when the «show OLD documents» knob is ON (default OFF); flipping it off
        // instantly drops the historical rows without touching the rest. Best-
        // effort: on any DB hiccup we just send none (only bridge-derived rows).
        $ownWhmcsIds = Gate::issuedHistoricalEnabled()
            ? $this->ownWhmcsInvoiceIds($clientId)
            : [];

        try {
            $result = $client->getIssuedForClient($clientId, $ownWhmcsIds);
        } catch (Throwable $e) {
            return $this->alert('warning', 'Δεν ήταν δυνατή η ανάκτηση των παραστατικών. Δοκίμασε ξανά σε λίγο.');
        }

        if (empty($result['ok']) || ! is_array($result['data'] ?? null)) {
            return $this->alert('warning', 'Δεν ήταν δυνατή η ανάκτηση των παραστατικών. Δοκίμασε ξανά σε λίγο.');
        }

        $rows = $result['data']['rows'] ?? [];
        if (! is_array($rows) || $rows === []) {
            return $this->alert('info', 'Δεν υπάρχουν εκδοθέντα παραστατικά ακόμη.');
        }

        $own = [];
        $third = [];
        foreach ($rows as $r) {
            if (! empty($r['is_own'])) {
                $own[] = $r;
            } else {
                $third[] = $r;
            }
        }

        $html = '<p class="text-muted">Τα παραστατικά που εκδόθηκαν για εσένα. '
            .'Μπορείς να κατεβάσεις το επίσημο PDF ή να επαληθεύσεις το παραστατικό στην ΑΑΔΕ.</p>';

        $html .= $this->section('Στο όνομά μου', $own, $link, false);
        if ($third !== []) {
            $html .= $this->section('Σε τρίτους (δρομολογημένα από εμένα)', $third, $link, true);
        }

        return $html;
    }

    /**
     * Proxy ONE issued invoice's PDF to the browser. Fetches the bytes from
     * ekdosi over HMAC (ekdosi re-checks that the document belongs to THIS
     * client and is publicly viewable), then streams them and exits. On any
     * failure it returns an error string for the page to render instead — so a
     * bad/expired/out-of-scope id degrades to a friendly message, never a broken
     * download.
     */
    public function streamPdf(array $vars, int $clientId, int $invoiceId): string
    {
        $link = htmlspecialchars($vars['modulelink'] ?? 'index.php?m=ekdosi_bridge');
        $back = ' <a class="btn btn-default btn-sm" href="'.$link.'&act=issued">Επιστροφή</a>';

        if ($invoiceId <= 0) {
            return $this->alert('warning', 'Μη έγκυρο παραστατικό.').$back;
        }

        $client = EkdosiClient::fromConfig();
        if ($client === null) {
            return $this->alert('info', 'Η υπηρεσία δεν είναι διαθέσιμη αυτή τη στιγμή.').$back;
        }

        try {
            $result = $client->getIssuedDocPdf($clientId, $invoiceId);
        } catch (Throwable $e) {
            return $this->alert('warning', 'Δεν ήταν δυνατή η λήψη του PDF. Δοκίμασε ξανά σε λίγο.').$back;
        }

        $body = (string) ($result['body'] ?? '');
        if (empty($result['ok']) || $body === '') {
            // 404 (out of scope / not viewable) or a transient error — both read
            // to the customer as "not available", never as "you're not allowed".
            return $this->alert('info', 'Το παραστατικό δεν είναι διαθέσιμο.').$back;
        }

        // Stream only if we can still own the response headers; otherwise fall
        // back to the page so we never emit a corrupt half-HTML/half-PDF body.
        if (headers_sent()) {
            return $this->alert('warning', 'Δεν ήταν δυνατή η προβολή του PDF. Δοκίμασε ξανά.').$back;
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="parastatiko-'.$invoiceId.'.pdf"');
        // No Content-Length: a shared host with zlib.output_compression=On would
        // gzip the body AFTER this header, so a byte count from the uncompressed
        // string would be wrong and truncate the PDF. Letting PHP terminate the
        // response (chunked/EOF) keeps it correct with or without compression.
        header('X-Content-Type-Options: nosniff');
        echo $body;
        exit;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function section(string $title, array $rows, string $link, bool $withParty): string
    {
        if ($rows === []) {
            return '';
        }

        $head = '<tr><th>Ημ/νία</th>';
        if ($withParty) {
            $head .= '<th>Δικαιούχος</th>';
        }
        $head .= '<th>Παραστατικό</th><th>Τύπος</th><th>Κατάσταση</th><th>ΜΑΡΚ</th><th></th></tr>';

        $body = '';
        foreach ($rows as $r) {
            $cols = 6 + ($withParty ? 1 : 0);
            $body .= $this->row($r, $link, $withParty, $cols);
        }

        return <<<HTML
<div class="panel panel-default">
  <div class="panel-heading"><strong>{$title}</strong></div>
  <div class="table-responsive">
    <table class="table table-striped" style="margin-bottom:0">
      <thead>{$head}</thead>
      <tbody>{$body}</tbody>
    </table>
  </div>
</div>
HTML;
    }

    /**
     * @param  array<string, mixed>  $r
     */
    private function row(array $r, string $link, bool $withParty, int $cols): string
    {
        $date = htmlspecialchars((string) ($r['issued_at'] ?? ''));
        $invcode = htmlspecialchars((string) ($r['invcode'] ?? ''));
        $type = htmlspecialchars((string) ($r['type'] ?? ''));
        $mark = (string) ($r['mydata_mark'] ?? '');
        $markCell = $mark !== '' ? htmlspecialchars($mark) : '<span class="text-muted">—</span>';

        $tds = '<td>'.($date !== '' ? $date : '<span class="text-muted">—</span>').'</td>';

        if ($withParty) {
            $name = htmlspecialchars((string) ($r['party_name'] ?? ''));
            $afm = (string) ($r['party_afm'] ?? '');
            $afmHtml = $afm !== '' ? '<br><small class="text-muted">ΑΦΜ '.htmlspecialchars($afm).'</small>' : '';
            $tds .= '<td>'.($name !== '' ? $name : '<span class="text-muted">—</span>').$afmHtml.'</td>';
        }

        $tds .= '<td>'.($invcode !== '' ? $invcode : '<span class="text-muted">—</span>').'</td>';
        $tds .= '<td>'.($type !== '' ? $type : '<span class="text-muted">—</span>').'</td>';
        $tds .= '<td>'.$this->statusBadge($r).'</td>';
        $tds .= '<td>'.$markCell.'</td>';
        $tds .= '<td class="text-right">'.$this->actions($r, $link).'</td>';

        return '<tr>'.$tds.'</tr>';
    }

    /**
     * @param  array<string, mixed>  $r
     */
    private function statusBadge(array $r): string
    {
        $local = (string) ($r['local_status'] ?? '');
        $mydata = (string) ($r['mydata_state'] ?? '');

        if ($mydata === 'CANCELLED' || $local === 'cancelled') {
            return '<span class="label label-danger">Ακυρωμένο</span>';
        }
        if ($mydata === 'VALID') {
            return '<span class="label label-success">Στο myDATA</span>';
        }
        if ($local === 'active') {
            return '<span class="label label-info">Εκδόθηκε</span>';
        }

        return '<span class="label label-default">—</span>';
    }

    /**
     * @param  array<string, mixed>  $r
     */
    private function actions(array $r, string $link): string
    {
        $out = '';
        if (! empty($r['has_pdf'])) {
            $id = (int) ($r['ekdosi_invoice_id'] ?? 0);
            if ($id > 0) {
                $pdfLink = $link.'&act=pdf&doc='.$id;
                $out .= '<a class="btn btn-xs btn-default" href="'.htmlspecialchars($pdfLink).'" target="_blank" rel="noopener" '
                    .'title="Άνοιγμα του επίσημου παραστατικού (PDF)"><i class="fa fa-file-pdf-o"></i> PDF</a> ';
            }
        }
        $verify = (string) ($r['verify_url'] ?? '');
        if ($verify !== '' && preg_match('#^https?://#i', $verify)) {
            // Context-aware label: a ΥΠΑΕΣ (provider) document points at the
            // πάροχος's official-copy page (e.g. InvoSign viewinvoice.php); a
            // direct-myDATA one points at the AADE QR verification page.
            $isProvider = ((string) ($r['verify_kind'] ?? '')) === 'provider';
            $label = $isProvider ? 'Προβολή παρόχου (ΥΠΑΕΣ)' : 'Επαλήθευση ΑΑΔΕ';
            $title = $isProvider ? 'Άνοιγμα του παραστατικού στον πάροχο (ΥΠΑΕΣ)' : 'Επαλήθευση του παραστατικού στην ΑΑΔΕ';
            $out .= '<a class="btn btn-xs btn-default" href="'.htmlspecialchars($verify, ENT_QUOTES).'" target="_blank" rel="noopener noreferrer" '
                .'title="'.htmlspecialchars($title, ENT_QUOTES).'"><i class="fa fa-external-link"></i> '.htmlspecialchars($label).'</a>';
        }
        if ($out === '') {
            $out = '<span class="text-muted">—</span>';
        }

        return $out;
    }

    /**
     * The logged-in client's OWN WHMCS invoice ids (newest first, capped),
     * gathered from tblinvoices.userid — the authoritative WHMCS-side scope for
     * the historical lookup. Best-effort: any DB error yields an empty list (the
     * page still shows the bridge-derived rows).
     *
     * @return list<int>
     */
    private function ownWhmcsInvoiceIds(int $clientId): array
    {
        try {
            return Capsule::table('tblinvoices')
                ->where('userid', $clientId)
                ->orderByDesc('id')
                ->limit(2000)
                ->pluck('id')
                ->map(static fn ($v): int => (int) $v)
                ->all();
        } catch (Throwable $e) {
            return [];
        }
    }

    private function alert(string $type, string $msg): string
    {
        return '<div class="alert alert-'.$type.'">'.htmlspecialchars($msg).'</div>';
    }
}
