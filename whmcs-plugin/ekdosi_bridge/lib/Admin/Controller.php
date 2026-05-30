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

        $insights = $this->insightsPanel();
        $invoicesLink = htmlspecialchars($link.'&action=invoices');

        return <<<EOF
<h2>Ekdosi Bridge</h2>
{$insights}
<p style="margin:12px 0">
    <a class="btn btn-primary" href="{$invoicesLink}">
        <i class="fa fa-list"></i> Λίστα τιμολογίων WHMCS → Ekdosi (ΤΠΥ / ΜΑΡΚ)
    </a>
</p>
<hr>
<p class="text-muted">Ή επιθεώρησε ένα συγκεκριμένο τιμολόγιο:</p>
<form action="{$link}&action=show" method="POST">
    <div class="form-inline">
        <input class="form-control" name="invoiceid" placeholder="Invoice ID (e.g. 12345)" type="text" required>
        <button class="btn btn-default" type="submit">Inspect</button>
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

        return <<<EOF
<table class="table table-condensed" style="max-width:640px">
    <tr><th style="width:200px">Γέφυρα</th><td>{$configured}</td></tr>
    <tr><th>Ekdosi</th><td>{$target}</td></tr>
    <tr><th>Έκδοση plugin</th><td>{$version}</td></tr>
    <tr><th>Παραστατικά τρίτων</th><td>{$tp}</td></tr>
</table>
EOF;
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

        $q = Capsule::table('tblinvoices')->orderBy('id', 'desc');
        if ($status !== 'All') {
            $q->where('status', $status);
        }
        $total = (clone $q)->count();
        $invoices = $q->forPage($page, $perPage)->get(['id', 'userid', 'date', 'total', 'status']);

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

        // «Τρίτος» is computed LOCALLY from mod_ekdosi_routing (one batch, no
        // ekdosi/inbox dependency) so it's correct for EVERY invoice on the
        // page — historical ones included, which never reach the inbox.
        $tpBuckets = ThirdPartyStore::bucketsForInvoices($invoices->all());

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

            [$badge, $invcode, $mark] = $this->stateCells(is_array($state) ? $state : null);
            $tpCell = $this->thirdPartyCell($tpBuckets[$id] ?? null);

            // Action: «Αποστολή» when not yet in ekdosi; «Άνοιγμα» otherwise.
            if ($state === null) {
                $action = '<form action="'.$link.'&action=push" method="POST" style="display:inline">'
                    .$token.'<input type="hidden" name="invoiceid" value="'.$id.'">'
                    .'<button class="btn btn-xs btn-primary" type="submit"><i class="fa fa-paper-plane"></i> Αποστολή</button></form>';
            } else {
                $action = '<a class="btn btn-xs btn-default" href="'.$link.'&action=show&invoiceid='.$id.'">Άνοιγμα</a>';
            }

            $rows .= '<tr>'
                .'<td><a href="'.$invHref.'">#'.$id.'</a></td>'
                .'<td>'.htmlspecialchars((string) $inv->date).'</td>'
                .'<td>'.$name.'</td>'
                .'<td>'.$tpCell.'</td>'
                .'<td class="text-right">'.htmlspecialchars(number_format((float) $inv->total, 2)).'</td>'
                .'<td>'.$badge.'</td>'
                .'<td>'.$invcode.'</td>'
                .'<td>'.$mark.'</td>'
                .'<td class="text-right">'.$action.'</td>'
                .'</tr>';
        }

        $pager = $this->pager($link, $status, $page, $perPage, $total);
        $statusTabs = $this->statusTabs($link, $status);
        $from = ($page - 1) * $perPage + 1;
        $to = min($page * $perPage, $total);

        return <<<EOF
<p><a class="btn btn-default" href="{$link}">&larr; Back</a></p>
<h2>Τιμολόγια WHMCS → Ekdosi</h2>
{$bridgeWarn}
{$statusTabs}
<p class="text-muted">Εμφάνιση {$from}–{$to} από {$total}.</p>
<table class="table table-striped table-condensed">
  <thead><tr>
    <th>WHMCS #</th><th>Ημ/νία</th><th>Πελάτης</th><th>Τρίτος (δικαιούχος)</th>
    <th class="text-right">Σύνολο</th><th>Κατάσταση ekdosi</th><th>ΤΠΥ</th><th>ΜΑΡΚ</th><th></th>
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

    /** Status filter tabs for the invoice list. */
    private function statusTabs(string $link, string $current): string
    {
        $tabs = '';
        foreach (['Paid' => 'Εξοφλημένα', 'Unpaid' => 'Ανεξόφλητα', 'All' => 'Όλα'] as $key => $label) {
            $active = $key === $current ? ' class="btn btn-xs btn-primary"' : ' class="btn btn-xs btn-default"';
            $tabs .= '<a'.$active.' href="'.$link.'&action=invoices&status='.$key.'">'.$label.'</a> ';
        }

        return '<p>'.$tabs.'</p>';
    }

    /** Prev/next pager for the invoice list. */
    private function pager(string $link, string $status, int $page, int $perPage, int $total): string
    {
        $pages = (int) ceil($total / $perPage);
        if ($pages <= 1) {
            return '';
        }
        $base = $link.'&action=invoices&status='.$status.'&p=';
        $prev = $page > 1
            ? '<a class="btn btn-default" href="'.$base.($page - 1).'">&larr; Προηγούμενα</a> '
            : '';
        $next = $page < $pages
            ? '<a class="btn btn-default" href="'.$base.($page + 1).'">Επόμενα &rarr;</a>'
            : '';

        return '<p>'.$prev.'<span class="text-muted">Σελίδα '.$page.'/'.$pages.'</span> '.$next.'</p>';
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
     * Read-only JSON map { "<invoiceid>": "<invoiced value>" } for the ids in
     * ?ids=1,2,3. Powers the invoice-LIST badge (the AdminAreaFooterOutput JS
     * fetches this and decorates each row). No state change → no CSRF; access
     * is already gated by addonmodules.php's admin session. Echoes + exits so
     * WHMCS doesn't wrap the JSON in admin chrome.
     */
    public function marks(array $vars): string
    {
        $ids = array_values(array_filter(
            array_map('intval', explode(',', (string) ($_GET['ids'] ?? ''))),
            static fn (int $id): bool => $id > 0
        ));

        $out = [];
        if ($ids !== []) {
            // Cap the batch so a crafted ?ids= can't ask for the whole table.
            $rows = Capsule::table('tblinvoices')
                ->whereIn('id', array_slice($ids, 0, 200))
                ->get(['id', 'invoiced']);
            foreach ($rows as $row) {
                $out[(int) $row->id] = (string) ($row->invoiced ?? '0');
            }
        }

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
