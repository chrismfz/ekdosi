<?php

namespace WHMCS\Module\Addon\EkdosiBridge\Admin;

use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\EkdosiBridge\BridgeLogStore;
use WHMCS\Module\Addon\EkdosiBridge\EkdosiClient;
use WHMCS\Module\Addon\EkdosiBridge\InvoiceMarkStore;
use WHMCS\Module\Addon\EkdosiBridge\RelidInspector;
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
 *   - reset: drop OUR ekdosi MARK (mod_ekdosi_invoice_marks) — kept
 *            for the rare rollback workflow (e.g. operator cancelled
 *            an invoice at AADE and needs to re-file). Never touches
 *            the legacy tblinvoices.invoiced flag.
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

        $insights = $this->insightsPanel();
        $invoicesLink = htmlspecialchars($link.'&action=invoices');

        return <<<EOF
<h2>Ekdosi Bridge</h2>
{$insights}
<p style="margin:12px 0">
    <a class="btn btn-primary" href="{$invoicesLink}">
        <i class="fa fa-list"></i> Λίστα τιμολογίων WHMCS → Ekdosi (ΤΠΥ / ΜΑΡΚ)
    </a>
    <a class="btn btn-default" href="{$link}&action=bridgeLog">
        <i class="fa fa-exchange"></i> Bridge logs (τι ρωτάει το ekdosi)
    </a>
</p>
<hr>
<p class="text-muted">Ή επιθεώρησε ένα συγκεκριμένο τιμολόγιο (και εκτός λίστας):</p>
<form action="{$link}&action=show" method="POST" class="form-inline" style="margin-bottom:8px">
    <div class="input-group" style="max-width:340px">
        <input class="form-control input-sm" name="invoiceid" placeholder="Invoice # (π.χ. 12345)" type="text" required>
        <span class="input-group-btn"><button class="btn btn-sm btn-default" type="submit"><i class="fa fa-search"></i> Επιθεώρηση</button></span>
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
<p style="margin-top:14px">
    <a class="btn btn-default" href="{$link}&action=prefs">
        <i class="fa fa-users"></i> Προτιμήσεις τρίτων (πελάτες · επαφές · δρομολόγηση)
    </a>
</p>
EOF;
    }

    /**
     * Insights header: at-a-glance bridge health for the addon landing —
     * configured?, ekdosi target, plugin version, third-party readiness. Pure
     * status (no writes); each piece degrades gracefully if a check fails.
     */
    private function insightsPanel(): string
    {
        $client = EkdosiClient::fromConfig();
        $configured = $client !== null
            ? '<span class="label label-success">ενεργό</span>'
            : '<span class="label label-warning">δεν έχει ρυθμιστεί</span>';

        $version = '—';
        if (function_exists('ekdosi_bridge_config')) {
            $cfg = ekdosi_bridge_config();
            $version = htmlspecialchars((string) ($cfg['version'] ?? '—'));
        }

        // ekdosi target (host only — never echo the secret).
        $target = '—';
        $rows = Capsule::table('tbladdonmodules')
            ->where('module', 'ekdosi_bridge')
            ->whereIn('setting', ['ekdosi_base_url', 'ekdosi_slug'])
            ->pluck('value', 'setting');
        $base = trim((string) ($rows['ekdosi_base_url'] ?? ''));
        $slug = trim((string) ($rows['ekdosi_slug'] ?? ''));
        if ($base !== '') {
            $host = parse_url($base, PHP_URL_HOST) ?: $base;
            $target = htmlspecialchars($host).($slug !== '' ? ' / '.htmlspecialchars($slug) : '');
        }

        $tp = '<span class="label label-default">ανενεργό</span>';
        if (ThirdPartyStore::hasOwnTables()) {
            $contacts = (int) Capsule::table(ThirdPartyStore::CONTACTS)->count();
            $routes = (int) Capsule::table(ThirdPartyStore::ROUTING)->count();
            $tp = '<span class="label label-success">έτοιμο</span> '
                .$contacts.' επαφές, '.$routes.' δρομολογήσεις';
        }

        // «Τελευταίο ερώτημα ekdosi» — the inbound-poll freshness from the bridge
        // log. STALE (>60′) or never = the silent-outage signal, shown here on the
        // landing where the WHMCS admin sees it daily.
        $lastPoll = BridgeLogStore::lastInboundPollAt();
        $pollCell = $this->pollFreshnessCell($lastPoll);

        return <<<EOF
<table class="table table-condensed" style="max-width:640px">
    <tr><th style="width:200px">Γέφυρα</th><td>{$configured}</td></tr>
    <tr><th>Ekdosi</th><td>{$target}</td></tr>
    <tr><th>Έκδοση plugin</th><td>{$version}</td></tr>
    <tr><th>Τελευταίο ερώτημα ekdosi</th><td>{$pollCell}</td></tr>
    <tr><th>Παραστατικά τρίτων</th><td>{$tp}</td></tr>
</table>
EOF;
    }

    /**
     * «Bridge logs» — the Plugin-API request log: what ekdosi asks resolve.php,
     * how often, and what fails. Read-only. The freshness banner is the
     * silent-outage tripwire (no inbound poll in >1h → red), and 401/422 rows
     * surface a wiped/rotated secret immediately.
     */
    public function bridgeLog(array $vars): string
    {
        $link = htmlspecialchars($vars['modulelink'] ?? 'addonmodules.php?module=ekdosi_bridge');
        $summary = BridgeLogStore::summary();
        $rows = BridgeLogStore::recent(80);

        $banner = $this->pollBanner($summary['last_inbound_poll_at']);
        $authNote = $summary['last_auth_fail_at'] !== null
            ? '<div class="alert alert-warning">Τελευταία αποτυχία ταυτοποίησης (401/422): <strong>'
                .htmlspecialchars((string) $summary['last_auth_fail_at']).'</strong> — έλεγξε ότι το '
                .'<em>webhook secret</em> εδώ ταιριάζει με αυτό που έχει το ekdosi.</div>'
            : '';

        $rowsHtml = '';
        foreach ($rows as $r) {
            $okBadge = ((int) $r->ok === 1)
                ? '<span class="label label-success">'.(int) $r->http_status.'</span>'
                : '<span class="label label-danger">'.(int) $r->http_status.'</span>';
            $rowsHtml .= '<tr>'
                .'<td style="white-space:nowrap">'.htmlspecialchars((string) $r->created_at).'</td>'
                .'<td><code>'.htmlspecialchars((string) $r->op).'</code></td>'
                .'<td>'.$okBadge.'</td>'
                .'<td>'.htmlspecialchars((string) $r->result).'</td>'
                .'<td>'.htmlspecialchars((string) $r->ip).'</td>'
                .'</tr>';
        }
        if ($rowsHtml === '') {
            $rowsHtml = '<tr><td colspan="5" class="text-muted">Καμία καταγραφή ακόμα. '
                .'(Ο πίνακας δημιουργείται με την πρώτη επίσκεψη· οι κλήσεις του ekdosi καταγράφονται από εδώ και μπρος.)</td></tr>';
        }

        $total24 = (int) $summary['total24'];
        $fail24 = (int) $summary['fail24'];

        return <<<EOF
<h2>Bridge logs — τι ρωτάει το ekdosi</h2>
<p><a class="btn btn-default btn-sm" href="{$link}">&larr; Πίσω</a></p>
{$banner}
{$authNote}
<p class="text-muted">Τελευταίο 24ωρο: <strong>{$total24}</strong> αιτήματα, <strong>{$fail24}</strong> αποτυχίες. Κάθε κλήση του ekdosi στο Plugin-API (resolve.php) καταγράφεται εδώ.</p>
<table class="table table-condensed table-striped">
    <thead><tr><th>Ώρα</th><th>Op</th><th>HTTP</th><th>Αποτέλεσμα</th><th>IP</th></tr></thead>
    <tbody>{$rowsHtml}</tbody>
</table>
EOF;
    }

    /** Big freshness banner for the «Bridge logs» page. */
    private function pollBanner(?string $lastPoll): string
    {
        if ($lastPoll === null) {
            return '<div class="alert alert-warning"><strong>Το ekdosi δεν έχει ζητήσει ποτέ τη feed.</strong> '
                .'Αν ο sync υποτίθεται ότι τρέχει, έλεγξε ρυθμίσεις/secret· αλλιώς θα εμφανιστεί εδώ με την πρώτη κλήση.</div>';
        }
        $age = time() - (int) strtotime($lastPoll);
        $ago = $this->agoLabel($lastPoll);
        if ($age > 3600) {
            return '<div class="alert alert-danger"><strong>⚠ Το ekdosi δεν έχει ρωτήσει εδώ και '.$ago.'</strong> '
                .'(τελευταίο: '.htmlspecialchars($lastPoll).'). Κανονικά ρωτάει κάθε ~15′ — έλεγξε scheduler/secret στη μεριά του ekdosi.</div>';
        }

        return '<div class="alert alert-success"><strong>Ενεργό.</strong> Τελευταίο ερώτημα feed: '.$ago
            .' ('.htmlspecialchars($lastPoll).').</div>';
    }

    /** Compact freshness label for the landing insights table. */
    private function pollFreshnessCell(?string $lastPoll): string
    {
        if ($lastPoll === null) {
            return '<span class="label label-warning">ποτέ</span>';
        }
        $age = time() - (int) strtotime($lastPoll);
        $cls = $age > 3600 ? 'label-danger' : 'label-success';

        return '<span class="label '.$cls.'">'.$this->agoLabel($lastPoll).'</span> '
            .'<span class="text-muted" style="font-size:11px">'.htmlspecialchars($lastPoll).'</span>';
    }

    /** Human "X πριν" for a stored datetime (server time, same clock as records). */
    private function agoLabel(string $datetime): string
    {
        $ts = strtotime($datetime);
        if ($ts === false) {
            return htmlspecialchars($datetime);
        }
        $s = max(0, time() - $ts);
        if ($s < 90) {
            return $s.'″ πριν';
        }
        if ($s < 5400) {
            return ((int) round($s / 60)).'′ πριν';
        }
        if ($s < 129600) {
            return ((int) round($s / 3600)).' ώρες πριν';
        }

        return ((int) round($s / 86400)).' μέρες πριν';
    }

    /**
     * Consolidated invoice list: this WHMCS install's recent tblinvoices, each
     * cross-referenced LIVE with ekdosi (ΤΠΥ + ΜΑΡΚ + κατάσταση) via ONE batch
     * call. Replaces the "go open each invoice to see its state" workflow and
     * the bare "—" on the native WHMCS list. Read-only except the per-row
     * «Αποστολή» (reuses the existing push action → ekdosi inbox).
     *
     * Paging: ?p=N (50/page). Filter: ?status=Paid|Unpaid|... (default Paid).
     */
    public function invoices(array $vars): string
    {
        $link = htmlspecialchars($vars['modulelink'] ?? 'addonmodules.php?module=ekdosi_bridge');
        $perPage = 50;
        $page = max(1, (int) ($_GET['p'] ?? 1));
        $status = (string) ($_GET['status'] ?? 'Paid');
        if (! in_array($status, ['Paid', 'Unpaid', 'Cancelled', 'Refunded', 'All'], true)) {
            $status = 'Paid';
        }

        // Date window — default «τελευταία εβδομάδα» (η συνηθέστερη ματιά «τι
        // κόψαμε πρόσφατα»). week / month / quarter / all.
        $period = (string) ($_GET['period'] ?? 'week');
        if (! in_array($period, ['week', 'month', 'quarter', 'all'], true)) {
            $period = 'week';
        }

        $q = Capsule::table('tblinvoices')->orderBy('id', 'desc');
        if ($status !== 'All') {
            $q->where('status', $status);
        }
        $cutoff = self::periodCutoff($period);
        if ($cutoff !== null) {
            $q->where('date', '>=', $cutoff);
        }
        $total = (clone $q)->count();
        $invoices = $q->forPage($page, $perPage)->get(['id', 'userid', 'date', 'total', 'status', 'invoiced']);

        if ($invoices->isEmpty()) {
            return '<p><a class="btn btn-default" href="'.$link.'">&larr; Back</a></p>'
                .'<div class="alert alert-info">Κανένα τιμολόγιο για το φίλτρο «'.htmlspecialchars($status).'».</div>';
        }

        // Client names in one query.
        $userIds = $invoices->pluck('userid')->unique()->filter()->all();
        $clients = $userIds === []
            ? collect()
            : Capsule::table('tblclients')->whereIn('id', $userIds)
                ->get(['id', 'firstname', 'lastname', 'companyname'])->keyBy('id');

        // Batch ekdosi state for this page's ids (one call; degrades to empty).
        $states = [];
        $client = EkdosiClient::fromConfig();
        if ($client !== null) {
            $resp = $client->getInvoiceStates($invoices->pluck('id')->map(fn ($i) => (int) $i)->all());
            if (! empty($resp['ok']) && isset($resp['data']['states']) && is_array($resp['data']['states'])) {
                $states = $resp['data']['states'];
            }
        }
        $bridgeWarn = $client === null
            ? '<div class="alert alert-warning">Η γέφυρα δεν έχει ρυθμιστεί — η στήλη κατάστασης ekdosi είναι κενή.</div>'
            : '';

        // HISTORICAL fallback (deterministic, legacy_id): rows with no forward
        // ekdosi state but a legacy filing (tblinvoices.invoiced > 0 = the legacy
        // ekdosi INVOICE_ID == invoices.legacy_id). One batch call lights up the
        // imported invoices' ΤΠΥ + ΜΑΡΚ — the whole point of the list. (Negative
        // sentinels -1000/-333/-1 are not legacy ids → skipped.)
        $histByLegacy = [];   // legacy_id (string) => {ekdosi_invcode, mydata_mark, mydata_state, …}
        if ($client !== null) {
            $legacyIds = [];
            foreach ($invoices as $inv) {
                $hasForward = isset($states[(string) (int) $inv->id]) || isset($states[(int) $inv->id]);
                $legacy = (int) ($inv->invoiced ?? 0);
                if (! $hasForward && $legacy > 0) {
                    $legacyIds[$legacy] = $legacy;
                }
            }
            if ($legacyIds !== []) {
                $hResp = $client->invoicesByLegacyId(array_values($legacyIds));
                if (! empty($hResp['ok']) && isset($hResp['data']['invoices']) && is_array($hResp['data']['invoices'])) {
                    $histByLegacy = $hResp['data']['invoices'];
                }
            }
        }

        // «Τρίτος» is computed LOCALLY from mod_ekdosi_routing (one batch, no
        // ekdosi/inbox dependency) so it's correct for EVERY invoice on the
        // page — historical ones included, which never reach the inbox.
        $tpBuckets = ThirdPartyStore::bucketsForInvoices($invoices->all());

        // «Είδος» (τιμολόγιο vs απόδειξη): the client's "θέλω τιμολόγιο" intent
        // from their WHMCS custom field, per client, in one batch.
        $wantsInvoice = $this->wantsInvoiceByClient($userIds);

        // «Άμεσο» (immediate-invoice flag): which clients want fast issuance, so
        // their still-unfiled rows light up RED — the WHMCS-side mirror of the
        // ekdosi inbox's red badge. Best-effort (no distinct field → no reds).
        $immediate = $this->griniarisByClient($userIds);

        // relid warning per invoice (ONE batch query) — ⚠ N links straight to the
        // relid manager (relidCheck), the same one Inspect / manage-invoice use.
        $relidCounts = RelidInspector::activeCountsForInvoices(
            $invoices->pluck('id')->map(static fn ($i) => (int) $i)->all()
        );

        $token = $this->csrfField();
        $rows = '';
        foreach ($invoices as $inv) {
            $id = (int) $inv->id;
            $clientRow = $clients->get($inv->userid);
            $name = $clientRow
                ? htmlspecialchars(trim((string) $clientRow->companyname) !== ''
                    ? (string) $clientRow->companyname
                    : trim($clientRow->firstname.' '.$clientRow->lastname))
                : '—';
            $invHref = htmlspecialchars('invoices.php?action=edit&id='.$id);
            $state = $states[(string) $id] ?? ($states[$id] ?? null);

            // Forward state (pushed via the bridge) wins; else fall back to the
            // deterministic historical hit (filed in the legacy app), keyed by
            // tblinvoices.invoiced == ekdosi legacy_id.
            $legacy = (int) ($inv->invoiced ?? 0);
            $hist = ($state === null && $legacy > 0 && isset($histByLegacy[(string) $legacy]) && is_array($histByLegacy[(string) $legacy]))
                ? $histByLegacy[(string) $legacy]
                : null;

            if (is_array($state)) {
                [$badge, $invcode, $mark] = $this->stateCells($state);
            } elseif ($hist !== null) {
                [$badge, $invcode, $mark] = $this->legacyCells($hist);
            } else {
                [$badge, $invcode, $mark] = $this->stateCells(null);
            }
            $tpCell = $this->thirdPartyCell($tpBuckets[$id] ?? null);

            // Action: «Αποστολή» only when ekdosi knows NOTHING about it (neither
            // forward nor legacy). A legacy-filed invoice is already at AADE —
            // offering «Αποστολή» would invite a double filing — so show «Άνοιγμα».
            if ($state === null && $hist === null) {
                $action = '<form action="'.$link.'&action=push" method="POST" style="display:inline">'
                    .$token.'<input type="hidden" name="invoiceid" value="'.$id.'">'
                    .'<button class="btn btn-xs btn-primary" type="submit"><i class="fa fa-paper-plane"></i> Αποστολή</button></form>';
            } else {
                $action = '<a class="btn btn-xs btn-default" href="'.$link.'&action=show&invoiceid='.$id.'">Άνοιγμα</a>';
            }

            $kind = $this->kindCell($wantsInvoice[(int) $inv->userid] ?? null);

            // «Άμεσο»: badge on the client name; RED row only while the invoice is
            // still unfiled (state === null && hist === null → it shows «Αποστολή»),
            // i.e. the operator still needs to act. A filed immediate row keeps the
            // badge but not the red (it's done).
            $isImmediate = $immediate[(int) $inv->userid] ?? false;
            $rowClass = ($isImmediate && $state === null && $hist === null) ? ' class="danger"' : '';
            if ($isImmediate) {
                $name .= ' <span class="label label-danger" title="Άμεση τιμολόγηση — ο πελάτης ζητά άμεση έκδοση"><i class="fa fa-bolt"></i> Άμεσο</span>';
            }

            $relidN = $relidCounts[$id] ?? 0;
            $relidCell = $relidN > 0
                ? '<a href="'.$link.'&action=show&invoiceid='.$id.'" class="label label-warning" '
                    .'title="'.$relidN.' γραμμές με ενεργό relid — δες/μηδένισε πριν το Mark Paid">⚠ '.$relidN.'</a>'
                : '<span class="text-muted">—</span>';

            $rows .= '<tr'.$rowClass.'>'
                .'<td><a href="'.$invHref.'">#'.$id.'</a></td>'
                .'<td>'.htmlspecialchars((string) $inv->date).'</td>'
                .'<td>'.$name.'</td>'
                .'<td>'.$tpCell.'</td>'
                .'<td>'.$kind.'</td>'
                .'<td class="text-right">'.htmlspecialchars(number_format((float) $inv->total, 2)).'</td>'
                .'<td>'.$badge.'</td>'
                .'<td>'.$invcode.'</td>'
                .'<td>'.$mark.'</td>'
                .'<td>'.$relidCell.'</td>'
                .'<td class="text-right">'.$action.'</td>'
                .'</tr>';
        }

        $pager = $this->pager($link, $status, $page, $perPage, $total, $period);
        $statusTabs = $this->statusTabs($link, $status, $period);
        $periodTabs = $this->periodTabs($link, $status, $period);
        $from = ($page - 1) * $perPage + 1;
        $to = min($page * $perPage, $total);

        // Jump-by-ID: reach a specific invoice even when it's outside the current
        // status/period filter (an old #12345 that doesn't show in «εβδομάδα»).
        // Same target as a row's «Άνοιγμα» — one door to the per-invoice detail,
        // so the standalone landing form is no longer needed.
        $jump = '<form action="'.$link.'&action=show" method="POST" class="form-inline" style="margin:0 0 10px">'
            .'<div class="input-group" style="max-width:340px">'
            .'<input class="form-control input-sm" name="invoiceid" placeholder="Μετάβαση σε τιμολόγιο #… (και εκτός φίλτρου)" type="text" required>'
            .'<span class="input-group-btn"><button class="btn btn-sm btn-default" type="submit">'
            .'<i class="fa fa-search"></i> Επιθεώρηση</button></span>'
            .'</div></form>';

        return <<<EOF
<p><a class="btn btn-default" href="{$link}">&larr; Back</a></p>
<h2>Τιμολόγια WHMCS → Ekdosi</h2>
{$bridgeWarn}
{$jump}
{$periodTabs}
{$statusTabs}
<p class="text-muted">Εμφάνιση {$from}–{$to} από {$total}.</p>
<table class="table table-striped table-condensed">
  <thead><tr>
    <th>WHMCS #</th><th>Ημ/νία</th><th>Πελάτης</th><th>Τρίτος (δικαιούχος)</th><th>Είδος</th>
    <th class="text-right">Σύνολο</th><th>Κατάσταση ekdosi</th><th>ΤΠΥ</th><th>ΜΑΡΚ</th><th>relid</th><th></th>
  </tr></thead>
  <tbody>{$rows}</tbody>
</table>
{$pager}
EOF;
    }

    /**
     * Render the three ekdosi state cells (badge, ΤΠΥ, ΜΑΡΚ) for one row.
     *
     * @param  array<string, mixed>|null  $state
     * @return array{0:string,1:string,2:string}
     */
    private function stateCells(?array $state): array
    {
        $dash = '<span class="text-muted">—</span>';
        if ($state === null) {
            return ['<span class="label label-default" title="Δεν έχει σταλεί στο ekdosi">Δεν στάλθηκε</span>', $dash, $dash];
        }

        $badge = $this->mapStatusBadge(
            (string) ($state['status'] ?? ''),
            $state['local_status'] ?? null,
            $state['mydata_state'] ?? null,
        );
        $invcode = ($state['ekdosi_invcode'] ?? null)
            ? '<strong>'.htmlspecialchars((string) $state['ekdosi_invcode']).'</strong>' : $dash;
        $mark = ($state['mydata_mark'] ?? null)
            ? '<code>'.htmlspecialchars((string) $state['mydata_mark']).'</code>' : $dash;

        return [$badge, $invcode, $mark];
    }

    /**
     * Render the three cells for a HISTORICAL (legacy-filed) invoice — resolved
     * deterministically via tblinvoices.invoiced == ekdosi invoices.legacy_id.
     * Distinct «(legacy)» badge so it reads apart from a bridge-pushed filing;
     * the ΤΠΥ + ΜΑΡΚ are the real ekdosi/AADE values.
     *
     * @param  array<string, mixed>  $hist  {ekdosi_invcode, mydata_mark, mydata_state, …}
     * @return array{0:string,1:string,2:string}
     */
    private function legacyCells(array $hist): array
    {
        $dash = '<span class="text-muted">—</span>';
        $state = (string) ($hist['mydata_state'] ?? '');
        $cancelled = $state === 'CANCELLED';
        $badge = '<span class="label '.($cancelled ? 'label-danger' : 'label-success').'" '
            .'title="Τιμολογήθηκε στην παλιά εφαρμογή (αντιστοίχιση legacy_id)">'
            .($cancelled ? 'ΑΚΥΡΩΜΕΝΟ (legacy)' : 'Στο AADE (legacy)').'</span>';
        $invcode = ($hist['ekdosi_invcode'] ?? null)
            ? '<strong>'.htmlspecialchars((string) $hist['ekdosi_invcode']).'</strong>' : $dash;
        $mark = ($hist['mydata_mark'] ?? null)
            ? '<code>'.htmlspecialchars((string) $hist['mydata_mark']).'</code>' : $dash;

        return [$badge, $invcode, $mark];
    }

    /**
     * The «Τρίτος» cell: shows WHO the invoice is billed to when it's routed to
     * a third-party beneficiary — the contact name(s), not just yes/no. Resolved
     * locally from mod_ekdosi_routing.
     *
     * @param  array{bucket: string, names: list<string>}|null  $tp
     */
    private function thirdPartyCell(?array $tp): string
    {
        $bucket = $tp['bucket'] ?? 'none';
        $names = $tp['names'] ?? [];

        if ($bucket === 'single') {
            $who = htmlspecialchars((string) ($names[0] ?? 'τρίτος'));

            return '<span class="label label-info" title="Δρομολογείται στον δικαιούχο">'.$who.'</span>';
        }
        if ($bucket === 'multi') {
            $list = htmlspecialchars(implode(' · ', $names));
            $extra = $list !== '' ? ' title="'.$list.'"' : ' title="Πολλαπλοί δικαιούχοι — χρειάζεται διαχωρισμός"';

            return '<span class="label label-warning"'.$extra.'>Πολλοί ('.count($names).')</span>';
        }

        return '<span class="text-muted" title="Χρέωση στον πελάτη">—</span>';
    }

    /** Earliest `date` to include for a period token, or null for «all». */
    private static function periodCutoff(string $period): ?string
    {
        return match ($period) {
            'week' => date('Y-m-d', strtotime('-7 days')),
            'month' => date('Y-m-d', strtotime('-1 month')),
            'quarter' => date('Y-m-d', strtotime('-3 months')),
            default => null,   // 'all'
        };
    }

    /** Status filter tabs for the invoice list (preserves the period window). */
    private function statusTabs(string $link, string $current, string $period): string
    {
        $tabs = '';
        foreach (['Paid' => 'Εξοφλημένα', 'Unpaid' => 'Ανεξόφλητα', 'All' => 'Όλα'] as $key => $label) {
            $active = $key === $current ? ' class="btn btn-xs btn-primary"' : ' class="btn btn-xs btn-default"';
            $tabs .= '<a'.$active.' href="'.$link.'&action=invoices&status='.$key.'&period='.$period.'">'.$label.'</a> ';
        }

        return '<p>'.$tabs.'</p>';
    }

    /** Date-window tabs: last week / month / quarter / all (preserves the status). */
    private function periodTabs(string $link, string $status, string $current): string
    {
        $tabs = '';
        foreach (['week' => 'Εβδομάδα', 'month' => 'Μήνας', 'quarter' => 'Τρίμηνο', 'all' => 'Όλα'] as $key => $label) {
            $active = $key === $current ? ' class="btn btn-xs btn-success"' : ' class="btn btn-xs btn-default"';
            $tabs .= '<a'.$active.' href="'.$link.'&action=invoices&status='.$status.'&period='.$key.'">'.$label.'</a> ';
        }

        return '<p><strong>Περίοδος:</strong> '.$tabs.'</p>';
    }

    /** Prev/next pager for the invoice list (preserves status + period window). */
    private function pager(string $link, string $status, int $page, int $perPage, int $total, string $period): string
    {
        $pages = (int) ceil($total / $perPage);
        if ($pages <= 1) {
            return '';
        }
        $base = $link.'&action=invoices&status='.$status.'&period='.$period.'&p=';
        $prev = $page > 1
            ? '<a class="btn btn-default" href="'.$base.($page - 1).'">&larr; Προηγούμενα</a> '
            : '';
        $next = $page < $pages
            ? '<a class="btn btn-default" href="'.$base.($page + 1).'">Επόμενα &rarr;</a>'
            : '';

        return '<p>'.$prev.'<span class="text-muted">Σελίδα '.$page.'/'.$pages.'</span> '.$next.'</p>';
    }

    /**
     * «Είδος» cell from the client's "θέλω τιμολόγιο" intent: true → Τιμολόγιο,
     * false → Απόδειξη, null → unknown (field unmapped / not set).
     */
    private function kindCell(?bool $wants): string
    {
        if ($wants === true) {
            return '<span class="label label-primary" title="Ο πελάτης ζήτησε τιμολόγιο">Τιμολόγιο</span>';
        }
        if ($wants === false) {
            return '<span class="label label-default" title="Δεν ζήτησε τιμολόγιο → απόδειξη">Απόδειξη</span>';
        }

        return '<span class="text-muted" title="Άγνωστο">—</span>';
    }

    /**
     * Batch: per WHMCS client, did they ask for an invoice? Reads the client
     * custom field whose name matches the "θέλω τιμολόγιο" intent (resolved by
     * name, like the AFM field), for the page's clients in one query.
     *
     * @param  array<int, int>  $userIds
     * @return array<int, bool|null>   userid => wants-invoice (null = unknown)
     */
    private function wantsInvoiceByClient(array $userIds): array
    {
        $out = [];
        if ($userIds === []) {
            return $out;
        }

        try {
            $fieldId = (int) Capsule::table('tblcustomfields')
                ->where('type', 'client')
                ->where(function ($q): void {
                    $q->where('fieldname', 'like', '%τιμολ%')
                        ->orWhere('fieldname', 'like', '%τιμολόγιο%')
                        ->orWhere('fieldname', 'like', '%invoice%');
                })
                ->orderBy('id')
                ->value('id');
            if ($fieldId <= 0) {
                return $out;   // field not present → all unknown
            }

            $vals = Capsule::table('tblcustomfieldsvalues')
                ->where('fieldid', $fieldId)
                ->whereIn('relid', $userIds)
                ->pluck('value', 'relid');

            foreach ($userIds as $uid) {
                if (! isset($vals[$uid])) {
                    continue;   // leave unknown
                }
                $v = strtolower(trim((string) $vals[$uid]));
                $out[$uid] = in_array($v, ['on', 'yes', '1', 'true', 'ναι', 'checked'], true);
            }
        } catch (\Throwable $e) {
            return $out;
        }

        return $out;
    }

    /**
     * Batch: per WHMCS client, are they flagged «άμεση τιμολόγηση» (the legacy
     * γκρινιάρης immediate-invoice flag)? Resolved by field NAME, like
     * wantsInvoiceByClient — but the wantsinvoice field is EXCLUDED first so the
     * two can never collide on one field. Best-effort: if no distinctly-named
     * immediate field exists, every client is «unknown» and no row lights up
     * (graceful — no false reds). Mirrors the ekdosi-side `griniaris` role.
     *
     * @param  array<int, int>  $userIds
     * @return array<int, bool>   userid => immediate (absent = unknown/false)
     */
    private function griniarisByClient(array $userIds): array
    {
        $out = [];
        if ($userIds === []) {
            return $out;
        }

        try {
            // The wantsinvoice field id — so we never mistake it for the immediate one.
            $wantsId = (int) Capsule::table('tblcustomfields')
                ->where('type', 'client')
                ->where(function ($q): void {
                    $q->where('fieldname', 'like', '%τιμολ%')
                        ->orWhere('fieldname', 'like', '%invoice%');
                })
                ->orderBy('id')
                ->value('id');

            $fieldId = (int) Capsule::table('tblcustomfields')
                ->where('type', 'client')
                ->when($wantsId > 0, fn ($q) => $q->where('id', '!=', $wantsId))
                ->where(function ($q): void {
                    $q->where('fieldname', 'like', '%γκριν%')
                        ->orWhere('fieldname', 'like', '%griniaris%')
                        ->orWhere('fieldname', 'like', '%άμεσ%')
                        ->orWhere('fieldname', 'like', '%immediate%')
                        ->orWhere('fieldname', 'like', '%priority%');
                })
                ->orderBy('id')
                ->value('id');
            if ($fieldId <= 0) {
                return $out;   // no distinct immediate field → nobody flagged
            }

            $vals = Capsule::table('tblcustomfieldsvalues')
                ->where('fieldid', $fieldId)
                ->whereIn('relid', $userIds)
                ->pluck('value', 'relid');

            foreach ($userIds as $uid) {
                if (! isset($vals[$uid])) {
                    continue;
                }
                $v = strtolower(trim((string) $vals[$uid]));
                $out[$uid] = in_array($v, ['on', 'yes', '1', 'true', 'ναι', 'checked'], true);
            }
        } catch (\Throwable $e) {
            return $out;
        }

        return $out;
    }

    /**
     * Browse the synced third-party preferences (read-only). No userid → the
     * list of clients that have contacts and/or routing; with ?userid=N → that
     * client's contacts + service routing. The admin mirror of the legacy
     * "Παραστατικά σε τρίτους" view, over the bridge's own mod_ekdosi_* tables.
     */
    public function prefs(array $vars): string
    {
        $link = htmlspecialchars($vars['modulelink']);
        if (! ThirdPartyStore::hasOwnTables()) {
            return $this->errorPage($link, 'Δεν υπάρχουν ακόμη πίνακες — ενεργοποιήστε το addon ή τρέξτε «Sync from legacy timologia».');
        }

        $userid = (int) ($_GET['userid'] ?? 0);

        return $userid > 0
            ? $this->prefsClient($link, $userid)
            : $this->prefsList($link);
    }

    /** Client list: everyone with a contact and/or a route, with counts. */
    private function prefsList(string $link): string
    {
        $contactCounts = Capsule::table(ThirdPartyStore::CONTACTS)
            ->select('userid', Capsule::raw('COUNT(*) AS c'))
            ->groupBy('userid')->pluck('c', 'userid');
        $routeCounts = Capsule::table(ThirdPartyStore::ROUTING)
            ->select('userid', Capsule::raw('COUNT(*) AS c'))
            ->groupBy('userid')->pluck('c', 'userid');

        $userids = array_values(array_unique(array_merge(
            array_keys($contactCounts->all()),
            array_keys($routeCounts->all()),
        )));

        if ($userids === []) {
            return $this->errorPage($link, 'Καμία καταχωρημένη προτίμηση ακόμη. Τρέξτε «Sync from legacy timologia».');
        }

        $clients = Capsule::table('tblclients')->whereIn('id', $userids)
            ->get(['id', 'firstname', 'lastname', 'companyname'])->keyBy('id');

        $rows = '';
        foreach ($userids as $uid) {
            $client = $clients->get($uid);
            $name = $client
                ? htmlspecialchars(trim((string) $client->companyname) !== ''
                    ? (string) $client->companyname
                    : trim($client->firstname.' '.$client->lastname))
                : '—';
            $nc = (int) ($contactCounts[$uid] ?? 0);
            $nr = (int) ($routeCounts[$uid] ?? 0);
            $detail = $link.'&action=prefs&userid='.$uid;
            $rows .= '<tr><td>'.$name.' <span class="text-muted">#'.$uid.'</span></td>'
                .'<td>'.$nc.'</td><td>'.$nr.'</td>'
                .'<td class="text-right"><a class="btn btn-xs btn-primary" href="'.htmlspecialchars($detail).'">Προβολή</a></td></tr>';
        }

        $count = count($userids);

        return <<<EOF
<p><a class="btn btn-default" href="{$link}">&larr; Back</a></p>
<h2>Προτιμήσεις τρίτων — Πελάτες ({$count})</h2>
<table class="table table-striped">
    <thead><tr><th>Πελάτης</th><th>Επαφές</th><th>Δρομολογήσεις</th><th></th></tr></thead>
    <tbody>{$rows}</tbody>
</table>
EOF;
    }

    /** One client's contacts + service routing (read-only). */
    private function prefsClient(string $link, int $userid, ?string $flash = null): string
    {
        $client = Capsule::table('tblclients')->find($userid);
        $name = $client
            ? htmlspecialchars(trim((string) $client->companyname) !== ''
                ? (string) $client->companyname
                : trim($client->firstname.' '.$client->lastname))
            : ('#'.$userid);

        $contacts = ThirdPartyStore::contactsForUser($userid);
        $byId = [];
        $contactRows = '';
        foreach ($contacts as $c) {
            $byId[(int) $c->id] = (string) $c->company_name;
            $contactRows .= '<tr><td>'.htmlspecialchars((string) $c->company_name).'</td>'
                .'<td>'.htmlspecialchars((string) ($c->gr_vatno ?? '')).'</td>'
                .'<td>'.htmlspecialchars((string) ($c->tax_office ?? '')).'</td>'
                .'<td>'.htmlspecialchars((string) ($c->city ?? '')).'</td></tr>';
        }
        if ($contactRows === '') {
            $contactRows = '<tr><td colspan="4" class="text-muted">Καμία επαφή.</td></tr>';
        }

        // Contact <option> set for the routing selects (admin-side EDIT — the
        // operator can re-route a service to the correct beneficiary when the
        // customer set it wrong; takes effect on the NEXT invoice because
        // resolve.php reads mod_ekdosi_routing live). Mirrors the client v2
        // page's write path (ThirdPartyStore::setRouteForUser).
        $options = '<option value="0">— Στο όνομά του —</option>';
        foreach ($contacts as $c) {
            $options .= '<option value="'.(int) $c->id.'">'
                .htmlspecialchars((string) $c->company_name).'</option>';
        }

        $token = $this->csrfField();
        $serviceRows = '';
        foreach (ThirdPartyStore::servicesForUser($userid) as $s) {
            $sel = $this->optionsWithSelected($options, (int) ($s['contactid'] ?? 0));
            $checked = ! empty($s['is_receipt']) ? ' checked' : '';
            $serviceRows .= '<tr><td>'.htmlspecialchars((string) $s['label'])
                .' <span class="label label-default">'.htmlspecialchars((string) $s['service_type']).'</span></td>'
                .'<td><form method="POST" action="'.$link.'&action=route" class="form-inline">'
                .$token
                .'<input type="hidden" name="userid" value="'.$userid.'">'
                .'<input type="hidden" name="serviceid" value="'.(int) $s['serviceid'].'">'
                .'<input type="hidden" name="service_type" value="'.htmlspecialchars((string) $s['service_type']).'">'
                .'<select name="contactid" class="form-control input-sm">'.$sel.'</select> '
                .'<label class="checkbox-inline"><input type="checkbox" name="is_receipt" value="1"'.$checked.'> Απόδειξη</label> '
                .'<button class="btn btn-sm btn-primary">Αποθήκευση</button>'
                .'</form></td></tr>';
        }
        if ($serviceRows === '') {
            $serviceRows = '<tr><td colspan="2" class="text-muted">Καμία υπηρεσία / δρομολόγηση.</td></tr>';
        }

        $backList = $link.'&action=prefs';
        $flashHtml = $flash ?? '';

        return <<<EOF
<p><a class="btn btn-default" href="{$backList}">&larr; Όλοι οι πελάτες</a></p>
<h2>{$name} <span class="text-muted">#{$userid}</span></h2>
{$flashHtml}
<h3>Επαφές (δικαιούχοι τιμολόγησης)</h3>
<table class="table table-striped">
    <thead><tr><th>Επωνυμία</th><th>ΑΦΜ</th><th>ΔΟΥ</th><th>Πόλη</th></tr></thead>
    <tbody>{$contactRows}</tbody>
</table>
<h3>Δρομολόγηση υπηρεσιών</h3>
<p class="text-muted">Άλλαξε τον δικαιούχο μιας υπηρεσίας — ισχύει από το ΕΠΟΜΕΝΟ τιμολόγιο (το ekdosi διαβάζει τη δρομολόγηση ζωντανά).</p>
<table class="table table-striped">
    <thead><tr><th>Υπηρεσία</th><th>Εκδίδεται σε</th></tr></thead>
    <tbody>{$serviceRows}</tbody>
</table>
EOF;
    }

    /**
     * Admin-side write of a single service's routing (the operator re-routes a
     * service to the correct third-party beneficiary, or back to the client).
     * CSRF-guarded; reuses the SAME store write the client v2 page uses
     * (ThirdPartyStore::setRouteForUser, which verifies the contact belongs to
     * the user). Takes effect on the next invoice — resolve.php reads
     * mod_ekdosi_routing live, no cache.
     */
    public function route(array $vars): string
    {
        $link = htmlspecialchars($vars['modulelink'] ?? 'addonmodules.php?module=ekdosi_bridge');
        $userid = (int) ($_POST['userid'] ?? 0);
        if (! $this->csrfValid()) {
            return $this->csrfFailPage($link);
        }
        if ($userid <= 0) {
            return $this->errorPage($link, 'Λείπει το userid του πελάτη.');
        }

        $serviceId = (int) ($_POST['serviceid'] ?? 0);
        $serviceType = (string) ($_POST['service_type'] ?? '');

        // Guard: the service must actually belong to THIS client. setRouteForUser
        // already verifies the contact ownership; this keeps a crafted/stale POST
        // from writing an inert route row for someone else's service id (junk in
        // mod_ekdosi_routing). Match against the client's real services.
        $ownsService = false;
        foreach (ThirdPartyStore::servicesForUser($userid) as $s) {
            if ((int) $s['serviceid'] === $serviceId && (string) $s['service_type'] === $serviceType) {
                $ownsService = true;
                break;
            }
        }
        if (! $ownsService) {
            return $this->prefsClient($link, $userid,
                $this->alert('warning', 'Η υπηρεσία δεν ανήκει σε αυτόν τον πελάτη — δεν αποθηκεύτηκε.'));
        }

        $ok = ThirdPartyStore::setRouteForUser(
            $userid,
            $serviceId,
            $serviceType,
            (int) ($_POST['contactid'] ?? 0),
            ! empty($_POST['is_receipt']),
        );

        $this->logActivity('EkdosiBridge: admin re-routed service '
            .(int) ($_POST['serviceid'] ?? 0).' for client #'.$userid
            .' → contact '.(int) ($_POST['contactid'] ?? 0).($ok ? '' : ' (FAILED)'));

        $flash = $ok
            ? $this->alert('success', 'Η δρομολόγηση αποθηκεύτηκε — ισχύει από το επόμενο τιμολόγιο.')
            : $this->alert('warning', 'Αποτυχία: η επαφή δεν ανήκει σε αυτόν τον πελάτη.');

        return $this->prefsClient($link, $userid, $flash);
    }

    /**
     * T-1b-2: import the legacy mod_timologia* routing into the bridge's own
     * tables (re-runnable, idempotent). Read-only against the legacy tables.
     */
    public function sync(array $vars): string
    {
        $link = htmlspecialchars($vars['modulelink']);
        if (! $this->csrfValid()) {
            return $this->csrfFailPage($link);
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

    /**
     * THE unified per-invoice manager — one page with everything: the ekdosi
     * headline (ΜΑΡΚ/ΤΠΥ + «Τιμολογήθηκε στη legacy» + «Αποστολή»), the live
     * ekdosi status, and the full per-line relid manager. Reached from the
     * landing inspect box, the invoice-list jump-box / «Άνοιγμα» / relid column,
     * and the native manage-invoice «Άνοιγμα ekdosi» + «Έλεγχος relid» buttons.
     */
    public function show(array $vars): string
    {
        $link = htmlspecialchars($vars['modulelink'] ?? 'addonmodules.php?module=ekdosi_bridge');
        $invoiceId = (int) ($_POST['invoiceid'] ?? $_GET['invoiceid'] ?? 0);
        if ($invoiceId <= 0) {
            return $this->errorPage($link, 'Invalid invoice id.');
        }

        $invoice = Capsule::table('tblinvoices')->find($invoiceId);
        if (! $invoice) {
            return $this->errorPage($link, "Invoice #{$invoiceId} not found in tblinvoices.");
        }

        $invHref = htmlspecialchars('invoices.php?action=edit&id='.$invoiceId);

        // ekdosi headline (ΜΑΡΚ/ΤΠΥ + «Τιμολογήθηκε στη legacy» + «Αποστολή») —
        // the single shared renderer; reuses the row we already fetched.
        $summary = $this->ekdosiSummaryCompact($invoiceId, $link, $invoice);

        // Live ekdosi status. Short timeouts (see getInvoiceStatus) so a slow /
        // down ekdosi fails fast with a friendly note instead of hanging the page.
        $client = EkdosiClient::fromConfig();
        if ($client !== null) {
            $statusBlock = $this->renderStatusBlock($client->getInvoiceStatus($invoiceId));
        } else {
            $statusBlock = '<div class="alert alert-warning">'
                .'Bridge not configured: set base URL / slug / secret on the module config page.</div>';
        }

        // The per-line relid manager, embedded here so this is the ONE page.
        $relidSection = $this->relidSection($invoiceId, $link, (int) ($invoice->userid ?? 0));

        // Reset (rare/destructive) — «Αποστολή» lives in the summary header.
        $reset = '<form action="'.$link.'&action=reset" method="POST" style="display:inline-block;">'
            .$this->csrfField()
            .'<input type="hidden" name="invoiceid" value="'.$invoiceId.'">'
            .'<button class="btn btn-warning btn-sm" type="submit" '
            .'onclick="return confirm(\'Διαγραφή του ekdosi ΜΑΡΚ για το #'.$invoiceId.'; Μόνο αφού ακυρωθεί πρώτα στο AADE.\');">'
            .'Reset to unfiled</button></form>';

        return <<<EOF
<p><a class="btn btn-default" href="{$link}">&larr; Back</a>
   <a class="btn btn-default" href="{$invHref}">Invoice #{$invoiceId} στο WHMCS</a></p>
<h2>Διαχείριση τιμολογίου #{$invoiceId}</h2>
{$summary}
{$statusBlock}
<hr>
{$relidSection}
<hr>
<p>{$reset}</p>
EOF;
    }

    public function push(array $vars): string
    {
        $link = htmlspecialchars($vars['modulelink']);
        if (! $this->csrfValid()) {
            return $this->csrfFailPage($link);
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

    /**
     * Read-only JSON map { "<invoiceid>": "<MARK>" } for the ids in ?ids=1,2,3,
     * from our mod_ekdosi_invoice_marks table (NOT tblinvoices.invoiced). Powers
     * the invoice-LIST badge (the AdminAreaFooterOutput JS fetches this and
     * decorates each row). No state change → no CSRF; access is already gated by
     * addonmodules.php's admin session. Echoes + exits so WHMCS doesn't wrap the
     * JSON in admin chrome.
     */
    public function marks(array $vars): string
    {
        $ids = array_values(array_filter(
            array_map('intval', explode(',', (string) ($_GET['ids'] ?? ''))),
            static fn (int $id): bool => $id > 0
        ));

        // Cap the batch so a crafted ?ids= can't ask for the whole table.
        $out = $ids === [] ? [] : InvoiceMarkStore::map(array_slice($ids, 0, 200));

        header('Content-Type: application/json');
        echo json_encode($out);
        exit;
    }

    /**
     * Per-client 3-way mapping page: WHMCS # → ekdosi παραστατικό → ΜΑΡΚ +
     * κατάσταση, for every invoice of one WHMCS client that ekdosi knows about
     * (drafts included). Pulls it live from ekdosi's invoice-map endpoint.
     * Linked from the admin client profile (see hooks.php).
     */
    public function client(array $vars): string
    {
        $link = htmlspecialchars($vars['modulelink'] ?? 'addonmodules.php?module=ekdosi_bridge');
        $userid = (int) ($_GET['userid'] ?? 0);
        if ($userid <= 0) {
            return $this->errorPage($link, 'Λείπει το userid του πελάτη.');
        }

        $client = EkdosiClient::fromConfig();
        if ($client === null) {
            return $this->errorPage($link, 'Το bridge δεν έχει ρυθμιστεί (base URL / slug / secret).');
        }

        $result = $client->getClientInvoiceMap($userid);
        if (empty($result['ok'])) {
            $err = htmlspecialchars((string) ($result['data']['error'] ?? ('HTTP '.($result['http_status'] ?? '?'))));

            return $this->errorPage($link, 'Αποτυχία λήψης από ekdosi: '.$err);
        }

        $rows = $result['data']['rows'] ?? [];
        $clientHref = htmlspecialchars('clientssummary.php?userid='.$userid);

        // Live section: deterministic WHMCS # → ekdosi παραστατικό links from
        // pending_whmcs_invoices (new, post-cutover rows). Empty for the ~16k
        // historical invoices — those never went through the inbox.
        $liveSection = '';
        if ($rows === []) {
            $liveSection = '<div class="alert alert-info">Καμία εγγραφή inbox (νέα ροή) για αυτόν τον πελάτη ακόμη.</div>';
        } else {
            $body = '';
            foreach ($rows as $r) {
                $whmcs = (int) ($r['whmcs_invoice_id'] ?? 0);
                $invHref = htmlspecialchars('invoices.php?action=edit&id='.$whmcs);
                $invcode = $r['ekdosi_invcode'] ?? null;
                $mark = $r['mydata_mark'] ?? null;
                $body .= '<tr>'
                    .'<td><a href="'.$invHref.'">#'.$whmcs.'</a></td>'
                    .'<td>'.($invcode !== null ? htmlspecialchars((string) $invcode) : '<span class="text-muted">—</span>').'</td>'
                    .'<td>'.$this->mapStatusBadge((string) ($r['pending_status'] ?? ''), $r['local_status'] ?? null, $r['mydata_state'] ?? null).'</td>'
                    .'<td>'.($mark !== null && $mark !== '' ? '<code>'.htmlspecialchars((string) $mark).'</code>' : '<span class="text-muted">—</span>').'</td>'
                    .'</tr>';
            }
            $liveSection = <<<EOF
<table class="table table-striped">
  <thead><tr><th>WHMCS #</th><th>Παραστατικό ekdosi</th><th>Κατάσταση</th><th>ΜΑΡΚ</th></tr></thead>
  <tbody>{$body}</tbody>
</table>
EOF;
        }

        // Historical section: ΑΦΜ-matched. The legacy import preserved no
        // WHMCS↔ekdosi id link (verified NULL/empty in prod), so we match on
        // ΑΦΜ — the client's own VAT id plus any third-party contact ΑΦΜ they
        // route services to. This is what lights up the imported invoices.
        $afmSection = $this->afmInvoicesSection($client, $userid);

        return <<<EOF
<p><a class="btn btn-default" href="{$clientHref}">&larr; Πελάτης</a></p>
<h2>Παραστατικά ekdosi — πελάτης #{$userid}</h2>
<h3>Νέα ροή (WHMCS # → παραστατικό)</h3>
<p class="text-muted">Αντιστοίχιση WHMCS τιμολογίου → παραστατικού ekdosi → ΜΑΡΚ ΑΑΔΕ. Τα «Προσχέδια» έχουν παραστατικό αλλά δεν έχουν υποβληθεί ακόμη.</p>
{$liveSection}
<hr>
{$afmSection}
EOF;
    }

    /**
     * The ΑΦΜ-matched section: gather the client's ΑΦΜ set (their own VAT id +
     * every third-party contact's gr_vatno) and ask ekdosi for each ΑΦΜ's
     * invoices, grouped «Δικά του» vs «Τρίτοι». Read-only.
     */
    private function afmInvoicesSection(EkdosiClient $client, int $userid): string
    {
        // Own ΑΦΜ: this WHMCS install never used the native tblclients.tax_id
        // (VAT was never enabled) — the ΑΦΜ lives in a client custom field
        // ("ΑΦΜ / Vies Vat No"), the same field the legacy timologia/afm2name
        // plugins read. Resolve it from the custom field, not tax_id.
        $ownAfm = $this->digits($this->clientVatCustomField($userid));

        // Third-party ΑΦΜ: contacts this client routes services to.
        $contactAfms = [];
        if (ThirdPartyStore::hasOwnTables()) {
            foreach (ThirdPartyStore::contactsForUser($userid) as $c) {
                $a = $this->digits((string) ($c->gr_vatno ?? ''));
                if ($a !== '') {
                    $contactAfms[$a] = (string) ($c->company_name ?? '');
                }
            }
        }

        // Cast keys to string: PHP coerces all-numeric array keys to int, so
        // array_keys($contactAfms) would otherwise mix int (contacts) with the
        // string $ownAfm — and the own-vs-third-party grouping below relies on
        // a strict ($afm === $ownAfm) comparison. Normalise to string up front.
        $afms = array_values(array_unique(array_filter(
            array_merge([$ownAfm], array_map('strval', array_keys($contactAfms))),
            static fn (string $a): bool => $a !== '',
        )));

        if ($afms === []) {
            return '<h3>Ιστορικά (αντιστοίχιση ΑΦΜ)</h3>'
                .'<div class="alert alert-info">Ο πελάτης δεν έχει ΑΦΜ στο WHMCS — αδύνατη η αντιστοίχιση ιστορικού.</div>';
        }

        $resp = $client->getInvoicesByAfm($afms);
        if (empty($resp['ok'])) {
            $err = htmlspecialchars((string) ($resp['data']['error'] ?? ('HTTP '.($resp['http_status'] ?? '?'))));

            return '<h3>Ιστορικά (αντιστοίχιση ΑΦΜ)</h3>'
                .'<div class="alert alert-warning">Αποτυχία λήψης από ekdosi: '.$err.'</div>';
        }

        $afmMap = $resp['data']['afms'] ?? [];
        $blocks = '';
        foreach ($afms as $afm) {
            $isOwn = ($afm === $ownAfm);
            $label = $isOwn
                ? 'Δικά του <span class="label label-default">ΑΦΜ '.htmlspecialchars($afm).'</span>'
                : 'Τρίτος: '.htmlspecialchars($contactAfms[$afm] ?? '').' <span class="label label-default">ΑΦΜ '.htmlspecialchars($afm).'</span>';

            $entry = $afmMap[$afm] ?? null;
            if (! is_array($entry)) {
                $blocks .= '<h4>'.$label.'</h4><p class="text-muted">Καμία αντιστοίχιση πελάτη στο ekdosi.</p>';

                continue;
            }
            $invoices = $entry['invoices'] ?? [];
            if ($invoices === []) {
                $blocks .= '<h4>'.$label.'</h4><p class="text-muted">Κανένα παραστατικό.</p>';

                continue;
            }

            $trs = '';
            foreach ($invoices as $inv) {
                $invcode = (string) ($inv['ekdosi_invcode'] ?? '');
                $mark = $inv['mydata_mark'] ?? null;
                $issued = (string) ($inv['issued_at'] ?? '');
                $trs .= '<tr>'
                    .'<td>'.htmlspecialchars($issued).'</td>'
                    .'<td>'.($invcode !== '' ? htmlspecialchars($invcode) : '<span class="text-muted">—</span>').'</td>'
                    .'<td>'.$this->afmStateBadge($inv['local_status'] ?? null, $inv['mydata_state'] ?? null).'</td>'
                    .'<td>'.($mark !== null && $mark !== '' ? '<code>'.htmlspecialchars((string) $mark).'</code>' : '<span class="text-muted">—</span>').'</td>'
                    .'</tr>';
            }
            $name = htmlspecialchars((string) ($entry['customer_name'] ?? ''));
            $count = count($invoices);
            $blocks .= <<<EOF
<h4>{$label} <small class="text-muted">{$name} · {$count}</small></h4>
<table class="table table-striped table-condensed">
  <thead><tr><th>Ημ/νία</th><th>Παραστατικό</th><th>Κατάσταση</th><th>ΜΑΡΚ</th></tr></thead>
  <tbody>{$trs}</tbody>
</table>
EOF;
        }

        return '<h3>Ιστορικά (αντιστοίχιση ΑΦΜ)</h3>'
            .'<p class="text-muted">Όλα τα παραστατικά ekdosi που ταιριάζουν με το ΑΦΜ του πελάτη και των τρίτων δικαιούχων. '
            .'Ο ιστορικός σύνδεσμος WHMCS→παραστατικό δεν διατηρήθηκε στη μετάπτωση· η αντιστοίχιση γίνεται με ΑΦΜ.</p>'
            .$blocks;
    }

    /**
     * The client's ΑΦΜ from their WHMCS custom field. This install never used
     * the native tblclients.tax_id (VAT was never enabled at WHMCS setup), so
     * the ΑΦΜ lives in a client custom field. We resolve the field by name —
     * "ΑΦΜ / Vies Vat No" (id 13 on myip), matched case-insensitively on the
     * ΑΦΜ/VAT/ΦΠΑ tokens so it survives a field-id change or a re-install —
     * and read its value for this client. Returns '' if unset.
     *
     * Field selection is SCORED, not lowest-id: a broad "%VAT%" LIKE can also
     * hit unrelated fields ("VAT rate", "VAT scheme", "VAT exempt"), and an
     * older such field would win a naive ORDER BY id. So we rank candidates —
     * ΑΦΜ/Vies-number names score highest, rate/scheme/percent/category names
     * are excluded — and, among ties, prefer a field that actually holds a
     * value for THIS client. Resolution is independent of which field has the
     * lowest id.
     */
    private function clientVatCustomField(int $userid): string
    {
        try {
            $candidates = Capsule::table('tblcustomfields')
                ->where('type', 'client')
                ->where(function ($q): void {
                    $q->where('fieldname', 'like', '%ΑΦΜ%')
                        ->orWhere('fieldname', 'like', '%VAT%')
                        ->orWhere('fieldname', 'like', '%ΦΠΑ%')
                        ->orWhere('fieldname', 'like', '%Vies%');
                })
                ->orderBy('id')
                ->get(['id', 'fieldname']);

            $best = null;       // ['id' => int, 'score' => int]
            foreach ($candidates as $field) {
                $score = $this->vatFieldScore((string) $field->fieldname);
                if ($score <= 0) {
                    continue;   // excluded (rate/scheme/exempt/percent/category)
                }
                $value = (string) (Capsule::table('tblcustomfieldsvalues')
                    ->where('fieldid', $field->id)
                    ->where('relid', $userid)
                    ->value('value') ?? '');
                // Prefer a field that actually has a value for this client.
                $effective = $score + ($value !== '' ? 100 : 0);
                if ($best === null || $effective > $best['score']) {
                    $best = ['value' => $value, 'score' => $effective];
                }
            }

            return $best['value'] ?? '';
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * Rank a custom-field name as a ΑΦΜ/VAT-NUMBER holder. >0 = candidate
     * (higher is better); 0 = exclude (a VAT-related field that is NOT the
     * number — rate/scheme/percentage/exemption/category).
     */
    private function vatFieldScore(string $name): int
    {
        $n = mb_strtolower($name);
        // Exclude obvious non-number VAT fields outright.
        foreach (['rate', 'scheme', 'exempt', 'percent', 'category', 'απαλλαγ', 'συντελεστ', 'ποσοστ'] as $bad) {
            if (mb_strpos($n, $bad) !== false) {
                return 0;
            }
        }
        // Strongest signals: an explicit ΑΦΜ or Vies/VAT-number name.
        if (mb_strpos($n, 'αφμ') !== false) {
            return 30;
        }
        if (mb_strpos($n, 'vies') !== false || mb_strpos($n, 'vat no') !== false
            || mb_strpos($n, 'vat number') !== false || mb_strpos($n, 'vatno') !== false) {
            return 20;
        }
        // Generic VAT/ΦΠΑ — plausible but weakest.
        return 10;
    }

    /** Canonical ΑΦΜ: digits only (strip EL/GR prefix, spaces, dashes). */
    private function digits(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?? '';
    }

    /** Greek state badge from the two ekdosi statuses (no pending status here). */
    private function afmStateBadge(?string $localStatus, ?string $mydataState): string
    {
        if ($mydataState === 'CANCELLED') {
            return '<span class="label label-danger">Ακυρωμένο (ΑΑΔΕ)</span>';
        }
        if ($mydataState === 'VALID') {
            return '<span class="label label-success">Καταχωρημένο</span>';
        }
        if ($localStatus === 'draft') {
            return '<span class="label label-info">Προσχέδιο</span>';
        }
        if ($localStatus === 'cancelled') {
            return '<span class="label label-default">Ακυρωμένο</span>';
        }

        return '<span class="label label-warning">Χωρίς ΜΑΡΚ</span>';
    }

    /** Greek status badge from the pending status (+ myDATA hints). */
    private function mapStatusBadge(string $pendingStatus, ?string $localStatus, ?string $mydataState): string
    {
        if ($mydataState === 'CANCELLED') {
            return '<span class="label label-danger">Ακυρωμένο (ΑΑΔΕ)</span>';
        }

        return match ($pendingStatus) {
            'filed' => '<span class="label label-success">Καταχωρημένο</span>',
            'drafted' => '<span class="label label-info">Προσχέδιο</span>',
            'split' => '<span class="label label-info">Διαχωρισμένο</span>',
            'pending_review' => '<span class="label label-warning">Προς έλεγχο</span>',
            'held' => '<span class="label label-default">Σε αναμονή</span>',
            'rejected' => '<span class="label label-danger">Απορρίφθηκε</span>',
            default => '<span class="label label-default">'.htmlspecialchars($pendingStatus).'</span>',
        };
    }

    public function reset(array $vars): string
    {
        $link = htmlspecialchars($vars['modulelink']);
        if (! $this->csrfValid()) {
            return $this->csrfFailPage($link);
        }
        $invoiceId = (int) ($_POST['invoiceid'] ?? 0);
        if ($invoiceId <= 0) {
            return $this->errorPage($link, 'Invalid invoice id.');
        }
        // Forget OUR mark — never touch the legacy tblinvoices.invoiced flag.
        InvoiceMarkStore::forget($invoiceId);
        $this->logActivity("EkdosiBridge: cleared ekdosi MARK for invoice #{$invoiceId} (mod_ekdosi_invoice_marks).");
        $showLink = $link.'&action=show&invoiceid='.$invoiceId;
        return <<<EOF
<p><a class="btn btn-default" href="{$link}">&larr; Back</a></p>
<div class="alert alert-success">Invoice #{$invoiceId}: το ΜΑΡΚ ekdosi διαγράφηκε (unfiled).</div>
<p><a class="btn btn-primary" href="{$showLink}">Back to invoice</a></p>
EOF;
    }

    /**
     * Compact ekdosi headline for one invoice — the same info the native
     * manage-invoice sidebar shows: ΜΑΡΚ/ΤΠΥ (ekdosi/AADE), the legacy-filed
     * resolution, and a «Αποστολή στο ekdosi» button (only while not yet filed
     * at ekdosi). Rendered at the top of the unified invoice page. Cheap: no
     * live-status round-trip (the fuller status block does that). The caller
     * passes the already-fetched tblinvoices row to avoid a re-read.
     */
    private function ekdosiSummaryCompact(int $invoiceId, string $link, object $invoice): string
    {
        $mark = InvoiceMarkStore::get($invoiceId);
        $invcode = InvoiceMarkStore::invcodeFor($invoiceId);
        $filed = $mark !== null && $mark !== '';
        if (! $filed) {
            $markLabel = '<span class="label label-default" title="Καμία επιστροφή ΜΑΡΚ μέσω WHMCS">Όχι στο AADE μέσω WHMCS</span>';
        } else {
            $tpy = ($invcode !== null && $invcode !== '') ? ' ΤΠΥ '.htmlspecialchars($invcode).' ·' : '';
            $markLabel = '<span class="label label-success">Στο AADE ·'.$tpy.' ΜΑΡΚ '.htmlspecialchars($mark).'</span>';
        }

        $client = EkdosiClient::fromConfig();
        $legacyInvoiced = (int) ($invoice->invoiced ?? 0);
        $legacyLabel = '';
        if ($legacyInvoiced > 0) {
            $legacyLabel = ' <span class="label label-info">Τιμολογήθηκε στη legacy (INVOICE_ID='
                .htmlspecialchars((string) $legacyInvoiced).')</span>';
            if ($client !== null && ! $filed) {
                $resp = $client->invoicesByLegacyId([$legacyInvoiced]);
                $hit = $resp['data']['invoices'][(string) $legacyInvoiced] ?? null;
                if (is_array($hit)) {
                    $bits = [];
                    if ((string) ($hit['ekdosi_invcode'] ?? '') !== '') {
                        $bits[] = 'ΤΠΥ '.htmlspecialchars((string) $hit['ekdosi_invcode']);
                    }
                    if ((string) ($hit['mydata_mark'] ?? '') !== '') {
                        $bits[] = 'ΜΑΡΚ '.htmlspecialchars((string) $hit['mydata_mark']);
                    }
                    if ((string) ($hit['mydata_state'] ?? '') !== '') {
                        $bits[] = htmlspecialchars((string) $hit['mydata_state']);
                    }
                    if ($bits !== []) {
                        $legacyLabel .= ' <span class="label label-success">'.implode(' · ', $bits).' (ekdosi)</span>';
                    }
                }
            }
        }

        $send = '';
        if (! $filed) {
            $send = '<form action="'.$link.'&action=push" method="POST" style="display:inline-block; margin-left:8px;">'
                .$this->csrfField()
                .'<input type="hidden" name="invoiceid" value="'.$invoiceId.'">'
                .'<button class="btn btn-primary btn-sm" type="submit"><i class="fa fa-paper-plane"></i> Αποστολή στο ekdosi</button>'
                .'</form>';
        }
        return '<div class="well well-sm" style="margin-bottom:12px">'
            .'<strong>Ekdosi / myDATA:</strong> '.$markLabel.$legacyLabel.$send
            .'</div>';
    }

    /**
     * relid manager — now folded into the unified invoice page (show). Kept as a
     * thin alias so existing links/bookmarks (&action=relidCheck) still land on
     * the one page; new links point straight at &action=show.
     */
    public function relidCheck(array $vars): string
    {
        return $this->show($vars);
    }

    /**
     * Per-line relid manager body (warning + table + «Μηδενισμός relid»),
     * embedded in the unified invoice page (show). Read-only except the
     * CSRF-protected reset form. Shows which lines WHMCS would (re)renew at Mark
     * Paid (relid > 0) and flags the ones already renewed (next due in the
     * future = the double-renewal trap). The safe, audited successor to the
     * legacy relid_remover.
     */
    private function relidSection(int $invoiceId, string $link, int $userId): string
    {
        $items = RelidInspector::items($invoiceId);
        if ($items === []) {
            return '<h3>relid (αυτόματη ανανέωση WHMCS)</h3>'
                .'<div class="alert alert-info">Καμία γραμμή στο τιμολόγιο.</div>';
        }

        $active = RelidInspector::activeCount($items);
        $alreadyRenewed = RelidInspector::alreadyRenewedCount($items);
        $today = date('Y-m-d');

        // $userId (the invoice's tblclients id, passed in by show()) re-resolves a
        // zeroed line's would-be relid against the client's own domains/services.

        $rowsHtml = '';
        $restorable = 0;
        foreach ($items as $it) {
            $typeLabel = $it['service_type'] === 'domain'
                ? 'Domain'
                : ($it['service_type'] === 'hosting' ? 'Υπηρεσία' : htmlspecialchars($it['type'] !== '' ? $it['type'] : '—'));
            $nextDue = $it['next_due'] !== null ? htmlspecialchars($it['next_due']) : '—';
            $expiry = $it['expiry'] !== null ? htmlspecialchars($it['expiry']) : '—';
            $relidCell = $it['relid'] > 0 ? (string) $it['relid'] : '—';
            $dueBadge = $it['already_renewed']
                ? ' <span class="label label-danger" title="next due '.$nextDue.' > '.$today.'">ήδη ανανεωμένο</span>'
                : '';

            if ($it['active']) {
                // relid > 0 → can be ZEROED (item_id[] group).
                $cb = '<input type="checkbox" class="relid-item" name="item_id[]" value="'.$it['item_id'].'" checked>';
                $linkedCell = $it['linked'] !== null ? htmlspecialchars($it['linked']) : '—';
                $rowClass = $it['already_renewed'] ? 'danger' : 'warning';
            } else {
                // relid = 0 → offer a preview-confirmed RESTORE if we can resolve
                // the line to exactly one of the client's domains/services.
                $cand = RelidInspector::restoreCandidate($userId, $it);
                if ($cand !== null) {
                    $restorable++;
                    $cb = '<input type="checkbox" class="relid-restore" name="restore_item[]" value="'.$it['item_id'].'">';
                    $linkedCell = '<span class="text-success" title="Η Επαναφορά θα ξανασυνδέσει αυτή τη γραμμή">→ '
                        .htmlspecialchars($cand['label']).' <code>#'.$cand['relid'].'</code></span>';
                    $relidCell = '<span class="text-muted">0 → '.$cand['relid'].'</span>';
                } else {
                    $cb = '';
                    $linkedCell = '<span class="text-muted">—</span>';
                }
                $rowClass = '';
            }

            $rowsHtml .= '<tr class="'.$rowClass.'">'
                .'<td>'.$cb.'</td>'
                .'<td>'.htmlspecialchars($it['description']).'</td>'
                .'<td>'.$typeLabel.'</td>'
                .'<td>'.$linkedCell.'</td>'
                .'<td>'.$nextDue.$dueBadge.'</td>'
                .'<td>'.$expiry.'</td>'
                .'<td>'.$relidCell.'</td>'
                .'</tr>';
        }

        $warning = $active > 0
            ? '<div class="alert alert-warning"><strong>⚠ '.$active.' γραμμές με ενεργό relid.</strong> '
                .'Με <strong>Mark Paid</strong> το WHMCS θα (ξανα)ανανεώσει αυτές τις γραμμές.'
                .($alreadyRenewed > 0
                    ? ' <span class="label label-danger">'.$alreadyRenewed.' ήδη ανανεωμένες — κίνδυνος διπλής ανανέωσης!</span>'
                    : '')
                .'</div>'
            : '<div class="alert alert-success">Καμία γραμμή με ενεργό relid — ασφαλές για Mark Paid.</div>';

        $token = $this->csrfField();
        $resetAction = $link.'&action=relidReset';
        $restoreAction = $link.'&action=relidRestore';
        // Restore button only when there's at least one resolvable zeroed line.
        $restoreBtn = $restorable > 0
            ? '<button type="submit" class="btn btn-success" formaction="'.$restoreAction.'" '
                .'onclick="return confirm(\'Επαναφορά relid στις επιλεγμένες γραμμές; Θα ξανασυνδεθούν με το domain/service που εμφανίζεται και το WHMCS θα τις ανανεώνει στο Mark Paid.\');">'
                .'<i class="fa fa-undo"></i> Επαναφορά relid (επιλεγμένες)</button>'
            : '';

        return <<<EOF
<h3>relid (αυτόματη ανανέωση WHMCS)</h3>
{$warning}
<form method="post" action="{$resetAction}">
{$token}
<input type="hidden" name="invoiceid" value="{$invoiceId}">
<table class="table table-condensed">
    <thead><tr>
        <th>Επιλ.</th><th>Περιγραφή</th><th>Είδος</th><th>Σύνδεση</th><th>Επόμενη χρέωση (WHMCS)</th><th>Λήξη (registry)</th><th>relid</th>
    </tr></thead>
    <tbody>{$rowsHtml}</tbody>
</table>
<button type="button" class="btn btn-default btn-sm relid-select-all">Επιλογή όλων (ενεργά)</button>
<button type="button" class="btn btn-default btn-sm relid-select-none">Καμία</button>
<button type="submit" class="btn btn-danger" onclick="return confirm('Μηδενισμός relid στις επιλεγμένες γραμμές; Το Mark Paid δεν θα τις ανανεώσει.');"><i class="fa fa-eraser"></i> Μηδενισμός relid (επιλεγμένες)</button>
{$restoreBtn}
</form>
<p class="text-muted" style="margin-top:8px">Ο <strong>Μηδενισμός</strong> θέτει <code>relid = 0</code> (το Mark Paid δεν τις ανανεώνει). Η <strong>Επαναφορά</strong> ξανασυνδέει γραμμές με μηδενισμένο relid με το domain/service που εμφανίζεται (μόνο όσες ταιριάζουν μονοσήμαντα). Και τα δύο καταγράφονται στο WHMCS activity log.</p>
<script>
(function () {
    function setAll(v) { document.querySelectorAll('.relid-item').forEach(function (c) { c.checked = v; }); }
    var all = document.querySelector('.relid-select-all');
    var none = document.querySelector('.relid-select-none');
    if (all) all.addEventListener('click', function () { setAll(true); });
    if (none) none.addEventListener('click', function () { setAll(false); });
})();
</script>
EOF;
    }

    /**
     * Zero the relid on the selected invoice items (scoped to the invoice).
     * The safe, audited successor to the legacy relid_remover: prevents WHMCS
     * from (re)renewing those lines when the invoice is marked paid. Never
     * touches ekdosi/AADE.
     */
    public function relidReset(array $vars): string
    {
        $link = htmlspecialchars($vars['modulelink'] ?? 'addonmodules.php?module=ekdosi_bridge');
        if (! $this->csrfValid()) {
            return $this->csrfFailPage($link);
        }
        $invoiceId = (int) ($_POST['invoiceid'] ?? 0);
        $itemIds = array_values(array_unique(array_filter(
            array_map('intval', (array) ($_POST['item_id'] ?? [])),
            static fn ($v) => $v > 0,
        )));
        if ($invoiceId <= 0) {
            return $this->errorPage($link, 'Invalid invoice id.');
        }
        if ($itemIds === []) {
            return $this->errorPage($link, 'Δεν επιλέχθηκαν γραμμές.');
        }

        $updated = Capsule::table('tblinvoiceitems')
            ->where('invoiceid', $invoiceId)
            ->whereIn('id', $itemIds)
            ->update(['relid' => 0]);

        $this->logActivity("EkdosiBridge: relid set to 0 on {$updated} item(s) of invoice #{$invoiceId} (ids: "
            .implode(',', $itemIds).') — prevents double-renewal at Mark Paid.');

        $invHref = htmlspecialchars('invoices.php?action=edit&id='.$invoiceId);
        $backManager = htmlspecialchars($link.'&action=show&invoiceid='.$invoiceId);

        return <<<EOF
<p><a class="btn btn-default" href="{$link}">&larr; Back</a></p>
<div class="alert alert-success">Invoice #{$invoiceId}: μηδενίστηκε το relid σε {$updated} γραμμή/ές. Το Mark Paid δεν θα τις ανανεώσει.</div>
<p>
    <a class="btn btn-primary" href="{$backManager}">Επιστροφή στη διαχείριση #{$invoiceId}</a>
    <a class="btn btn-default" href="{$invHref}">Invoice #{$invoiceId} στο WHMCS</a>
</p>
EOF;
    }

    /**
     * Restore the relid on selected zeroed lines — the inverse of relidReset,
     * for a link removed by mistake. The would-be relid is RE-RESOLVED here on
     * the server (never trusted from the client) via RelidInspector::
     * restoreCandidate, and applied ONLY to a line that is still relid=0 and
     * resolves to exactly one of the client's domains/services. Ambiguous /
     * unresolvable lines are skipped and reported. Audited; never touches
     * ekdosi/AADE.
     */
    public function relidRestore(array $vars): string
    {
        $link = htmlspecialchars($vars['modulelink'] ?? 'addonmodules.php?module=ekdosi_bridge');
        if (! $this->csrfValid()) {
            return $this->csrfFailPage($link);
        }
        $invoiceId = (int) ($_POST['invoiceid'] ?? 0);
        $itemIds = array_values(array_unique(array_filter(
            array_map('intval', (array) ($_POST['restore_item'] ?? [])),
            static fn ($v) => $v > 0,
        )));
        if ($invoiceId <= 0) {
            return $this->errorPage($link, 'Invalid invoice id.');
        }
        if ($itemIds === []) {
            return $this->errorPage($link, 'Δεν επιλέχθηκαν γραμμές για επαναφορά.');
        }

        $invoice = Capsule::table('tblinvoices')->find($invoiceId);
        if (! $invoice) {
            return $this->errorPage($link, "Invoice #{$invoiceId} not found in tblinvoices.");
        }
        $userId = (int) ($invoice->userid ?? 0);

        // Re-resolve each selected line server-side (don't trust client input).
        $byId = [];
        foreach (RelidInspector::items($invoiceId) as $it) {
            $byId[$it['item_id']] = $it;
        }

        $restored = 0;
        $skipped = 0;
        $applied = [];
        foreach ($itemIds as $iid) {
            $it = $byId[$iid] ?? null;
            $cand = $it !== null ? RelidInspector::restoreCandidate($userId, $it) : null;
            if ($cand === null) {
                $skipped++;

                continue;
            }
            // Scope to relid=0 so a concurrently-set relid is never clobbered.
            $n = Capsule::table('tblinvoiceitems')
                ->where('invoiceid', $invoiceId)
                ->where('id', $iid)
                ->where('relid', 0)
                ->update(['relid' => $cand['relid']]);
            if ($n > 0) {
                $restored++;
                $applied[] = "#{$iid}→{$cand['label']}({$cand['relid']})";
            } else {
                $skipped++;
            }
        }

        $this->logActivity("EkdosiBridge: relid RESTORED on {$restored} item(s) of invoice #{$invoiceId} "
            .'('.($applied === [] ? '—' : implode(', ', $applied)).") — {$skipped} skipped (ambiguous/unresolved).");

        $invHref = htmlspecialchars('invoices.php?action=edit&id='.$invoiceId);
        $backManager = htmlspecialchars($link.'&action=show&invoiceid='.$invoiceId);
        $skipNote = $skipped > 0
            ? " ({$skipped} παραλείφθηκαν — ασαφής/ανεπίλυτη αντιστοίχιση· κάν' τες χειροκίνητα στο WHMCS.)"
            : '';

        return <<<EOF
<p><a class="btn btn-default" href="{$link}">&larr; Back</a></p>
<div class="alert alert-success">Invoice #{$invoiceId}: επαναφέρθηκε το relid σε {$restored} γραμμή/ές.{$skipNote}</div>
<p>
    <a class="btn btn-primary" href="{$backManager}">Επιστροφή στη διαχείριση #{$invoiceId}</a>
    <a class="btn btn-default" href="{$invHref}">Invoice #{$invoiceId} στο WHMCS</a>
</p>
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
        $invcode = $data['ekdosi_invcode'] ?? null;
        $notes = htmlspecialchars((string) ($data['notes'] ?? ''));
        $rejected = htmlspecialchars((string) ($data['rejected_reason'] ?? ''));
        // ΤΠΥ (invcode) — the deterministic ekdosi document number for THIS
        // WHMCS invoice. Lives here on the manage-invoice page, queried live.
        $invcodeRow = ($invcode !== null && $invcode !== '')
            ? '<li>Παραστατικό: <strong>'.htmlspecialchars((string) $invcode).'</strong></li>' : '';
        $markRow = $mark ? '<li>MARK: <code>'.htmlspecialchars((string) $mark).'</code></li>' : '';
        $rejRow = $rejected !== '' ? '<li>Rejected reason: '.$rejected.'</li>' : '';
        return <<<EOF
<div class="alert alert-info">
<strong>Ekdosi status: {$status}</strong>
<ul>
    {$invcodeRow}
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

    /** Bootstrap alert box (admin-side routing edit feedback). */
    private function alert(string $type, string $msg): string
    {
        return '<div class="alert alert-'.htmlspecialchars($type).'">'.htmlspecialchars($msg).'</div>';
    }

    /**
     * Render a <option> set with the matching id pre-selected. $options is the
     * pre-built option HTML (value="ID">label); we inject ` selected` into the
     * one whose value matches. Mirrors the client v2 controller helper.
     */
    private function optionsWithSelected(string $options, int $selectedId): string
    {
        $needle = 'value="'.$selectedId.'"';

        return str_replace($needle, $needle.' selected', $options);
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
        if (! function_exists('generate_token')) {
            return '';
        }
        $t = (string) generate_token('plain');
        // WHMCS 8.x 'plain' returns the RAW token string (not HTML); older
        // builds may return an <input>. Emit a real hidden input either way so
        // the token is actually submitted (don't rely on WHMCS auto-injecting
        // one into the form).
        if (stripos($t, '<input') !== false) {
            return $t;
        }

        return '<input type="hidden" name="token" value="'.htmlspecialchars($t, ENT_QUOTES).'">';
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
        if ($sent === '') {
            return false;
        }

        // The token WHMCS expects == generate_token('plain'), stable per
        // session. WHMCS 8.x returns the RAW token there (not an <input>), so
        // compare DIRECTLY first; older builds returned an <input value="X">
        // (either quote), so also try the embedded value; legacy builds used
        // $_SESSION['token']/'tokenval'. (8.x moved it off those session keys,
        // which broke the earlier key-based check.)
        $plain = trim((string) generate_token('plain'));
        if ($plain !== '' && hash_equals($plain, $sent)) {
            return true;
        }
        if (preg_match('/value=["\']([^"\']+)["\']/', $plain, $m) && hash_equals($m[1], $sent)) {
            return true;
        }
        foreach (['token', 'tokenval'] as $key) {
            $expected = (string) ($_SESSION[$key] ?? '');
            if ($expected !== '' && hash_equals($expected, $sent)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Non-sensitive one-liner shown on a CSRF failure so a still-broken install
     * is diagnosable WITHOUT leaking token values — only booleans + lengths.
     */
    private function csrfFailPage(string $link): string
    {
        $sent = (string) ($_POST['token'] ?? '');
        $plain = function_exists('generate_token') ? trim((string) generate_token('plain')) : '';
        $debug = sprintf(
            'sent=%s(len %d) · plain(len %d, input=%s) · direct_match=%s · sess[token]=%s · sess[tokenval]=%s',
            $sent !== '' ? 'Y' : 'N',
            strlen($sent),
            strlen($plain),
            stripos($plain, '<input') !== false ? 'Y' : 'N',
            ($plain !== '' && hash_equals($plain, $sent)) ? 'Y' : 'N',
            empty($_SESSION['token']) ? 'N' : 'Y',
            empty($_SESSION['tokenval']) ? 'N' : 'Y',
        );

        return $this->errorPage($link, 'Security token mismatch. Go back and retry.')
            .'<p class="text-muted" style="font-size:11px">debug: '.htmlspecialchars($debug).'</p>';
    }
}
