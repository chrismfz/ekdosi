<?php

namespace WHMCS\Module\Addon\EkdosiBridge\Client;

use WHMCS\Module\Addon\EkdosiBridge\ThirdPartyStore;

/**
 * T-2 (timologia v2): the client-area page "Παραστατικά σε τρίτους (v2)".
 *
 * A reseller manages their alternate billing identities (επαφές) and routes
 * each of their services (hosting/domains) to one of them — the successor to
 * the legacy `timologia` client area, writing to the bridge's OWN tables
 * (mod_ekdosi_*), NOT the legacy mod_timologia*.
 *
 * Everything is scoped to the logged-in client id (passed in, never taken from
 * the request) so a client can only ever touch their own rows. State-changing
 * actions are POST + CSRF-checked. Returns an HTML string rendered into the
 * module's clientpage.tpl.
 *
 * The page handler (ekdosi_bridge_clientarea) enforces Gate::visibleTo BEFORE
 * dispatching here, so a hidden page can't be reached by URL-guessing.
 */
class Controller
{
    public function render(array $vars, int $clientId): string
    {
        $link = htmlspecialchars($vars['modulelink'] ?? 'index.php?m=ekdosi_bridge');
        $act = isset($_REQUEST['act']) ? (string) $_REQUEST['act'] : 'index';

        // State-changing actions: POST + CSRF.
        if (in_array($act, ['save_contact', 'delete_contact', 'route'], true)) {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ! $this->csrfValid()) {
                return $this->alert('danger', 'Λήξη συνεδρίας ή μη έγκυρο αίτημα. Δοκίμασε ξανά.')
                    .$this->backButton($link);
            }
        }

        switch ($act) {
            case 'add_contact':
                return $this->contactForm($link, null);
            case 'edit_contact':
                $contact = ThirdPartyStore::contactForUser($clientId, (int) ($_GET['id'] ?? 0));

                return $contact
                    ? $this->contactForm($link, $contact)
                    : $this->alert('warning', 'Η επαφή δεν βρέθηκε.').$this->backButton($link);
            case 'save_contact':
                return $this->saveContact($link, $clientId);
            case 'delete_contact':
                ThirdPartyStore::deleteContactForUser($clientId, (int) ($_POST['id'] ?? 0));

                return $this->alert('success', 'Η επαφή διαγράφηκε.').$this->index($link, $clientId);
            case 'route':
                return $this->saveRoute($link, $clientId);
            default:
                return $this->index($link, $clientId);
        }
    }

    private function index(string $link, int $clientId): string
    {
        $contacts = ThirdPartyStore::contactsForUser($clientId);
        $services = ThirdPartyStore::servicesForUser($clientId);
        $token = $this->csrfField();

        // Contacts table.
        $contactRows = '';
        foreach ($contacts as $c) {
            $name = htmlspecialchars((string) $c->company_name);
            $afm = htmlspecialchars((string) $c->gr_vatno);
            $editLink = $link.'&act=edit_contact&id='.(int) $c->id;
            $contactRows .= '<tr><td>'.$name.'</td><td>'.$afm.'</td>'
                .'<td class="text-right">'
                .'<a class="btn btn-xs btn-default" href="'.htmlspecialchars($editLink).'">Επεξεργασία</a> '
                .'<form method="POST" action="'.$link.'&act=delete_contact" style="display:inline">'
                .$token.'<input type="hidden" name="id" value="'.(int) $c->id.'">'
                .'<button class="btn btn-xs btn-danger" onclick="return confirm(\'Διαγραφή επαφής; Θα αφαιρεθούν και οι δρομολογήσεις της.\')">Διαγραφή</button>'
                .'</form></td></tr>';
        }
        if ($contactRows === '') {
            $contactRows = '<tr><td colspan="3" class="text-muted">Καμία επαφή ακόμη.</td></tr>';
        }

        // Build the contact <option> set for the routing selects.
        $options = '<option value="0">— Έκδοση στο όνομά μου —</option>';
        foreach ($contacts as $c) {
            $options .= '<option value="'.(int) $c->id.'">'
                .htmlspecialchars((string) $c->company_name).'</option>';
        }

        // Services + routing table (one small form per service row).
        $serviceRows = '';
        foreach ($services as $s) {
            $label = htmlspecialchars($s['label']);
            $sel = $this->optionsWithSelected($options, $s['contactid'] ?? 0);
            $checked = $s['is_receipt'] ? ' checked' : '';
            $serviceRows .= '<tr><td>'.$label.' <span class="label label-default">'.$s['service_type'].'</span></td>'
                .'<td><form method="POST" action="'.$link.'&act=route" class="form-inline">'
                .$token
                .'<input type="hidden" name="serviceid" value="'.$s['serviceid'].'">'
                .'<input type="hidden" name="service_type" value="'.htmlspecialchars($s['service_type']).'">'
                .'<select name="contactid" class="form-control input-sm">'.$sel.'</select> '
                .'<label class="checkbox-inline"><input type="checkbox" name="is_receipt" value="1"'.$checked.'> Απόδειξη</label> '
                .'<button class="btn btn-sm btn-primary">Αποθήκευση</button>'
                .'</form></td></tr>';
        }
        if ($serviceRows === '') {
            $serviceRows = '<tr><td colspan="2" class="text-muted">Δεν βρέθηκαν υπηρεσίες.</td></tr>';
        }

        return <<<HTML
<p class="text-muted">Διαχείρισε τις επαφές σου (δικαιούχους τιμολόγησης) και όρισε σε ποιον εκδίδεται κάθε υπηρεσία.</p>
<div class="panel panel-default">
  <div class="panel-heading"><strong>Επαφές</strong>
    <a class="btn btn-xs btn-success pull-right" href="{$link}&act=add_contact">+ Νέα επαφή</a>
  </div>
  <table class="table table-striped" style="margin-bottom:0">
    <thead><tr><th>Επωνυμία</th><th>ΑΦΜ</th><th></th></tr></thead>
    <tbody>{$contactRows}</tbody>
  </table>
</div>
<div class="panel panel-default">
  <div class="panel-heading"><strong>Δρομολόγηση υπηρεσιών</strong></div>
  <table class="table table-striped" style="margin-bottom:0">
    <thead><tr><th>Υπηρεσία</th><th>Δικαιούχος</th></tr></thead>
    <tbody>{$serviceRows}</tbody>
  </table>
</div>
HTML;
    }

    private function contactForm(string $link, ?object $contact): string
    {
        $token = $this->csrfField();
        $id = $contact ? (int) $contact->id : 0;
        $v = fn (string $f) => htmlspecialchars((string) ($contact->$f ?? ''));
        $title = $contact ? 'Επεξεργασία επαφής' : 'Νέα επαφή';

        return <<<HTML
<h3>{$title}</h3>
<form method="POST" action="{$link}&act=save_contact" class="form-horizontal">
  {$token}
  <input type="hidden" name="id" value="{$id}">
  {$this->field('company_name', 'Επωνυμία *', $v('company_name'), true)}
  {$this->field('gr_vatno', 'ΑΦΜ *', $v('gr_vatno'), true)}
  {$this->field('tax_office', 'ΔΟΥ', $v('tax_office'))}
  {$this->field('vies_vatno', 'VIES VAT', $v('vies_vatno'))}
  {$this->field('address1', 'Διεύθυνση', $v('address1'))}
  {$this->field('address2', 'Διεύθυνση 2', $v('address2'))}
  {$this->field('city', 'Πόλη', $v('city'))}
  {$this->field('postal_code', 'Τ.Κ.', $v('postal_code'))}
  {$this->field('country', 'Χώρα', $v('country'))}
  {$this->field('description', 'Δραστηριότητα', $v('description'))}
  {$this->field('email', 'Email', $v('email'))}
  {$this->field('telephone', 'Τηλέφωνο', $v('telephone'))}
  <div class="form-group"><div class="col-sm-offset-3 col-sm-9">
    <button class="btn btn-primary">Αποθήκευση</button>
    <a class="btn btn-default" href="{$link}">Άκυρο</a>
  </div></div>
</form>
HTML;
    }

    private function saveContact(string $link, int $clientId): string
    {
        $input = [];
        foreach (ThirdPartyStore::CONTACT_FIELDS as $f) {
            $input[$f] = (string) ($_POST[$f] ?? '');
        }
        if (trim($input['company_name']) === '' || trim($input['gr_vatno']) === '') {
            return $this->alert('warning', 'Η επωνυμία και το ΑΦΜ είναι υποχρεωτικά.')
                .$this->contactForm($link, (object) $input);
        }

        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $ok = ThirdPartyStore::updateContactForUser($clientId, $id, $input);
            if (! $ok) {
                return $this->alert('danger', 'Η επαφή δεν βρέθηκε.').$this->index($link, $clientId);
            }
            $msg = 'Η επαφή ενημερώθηκε.';
        } else {
            ThirdPartyStore::createContactForUser($clientId, $input);
            $msg = 'Η επαφή δημιουργήθηκε.';
        }

        return $this->alert('success', $msg).$this->index($link, $clientId);
    }

    private function saveRoute(string $link, int $clientId): string
    {
        $serviceid = (int) ($_POST['serviceid'] ?? 0);
        $type = (string) ($_POST['service_type'] ?? '');
        $contactid = (int) ($_POST['contactid'] ?? 0);
        $isReceipt = ! empty($_POST['is_receipt']);

        if (! in_array($type, ['hosting', 'domain'], true) || $serviceid <= 0) {
            return $this->alert('danger', 'Μη έγκυρη υπηρεσία.').$this->index($link, $clientId);
        }

        if ($contactid === 0) {
            ThirdPartyStore::clearRouteForUser($clientId, $serviceid, $type);
            $msg = 'Η υπηρεσία θα εκδίδεται στο όνομά σου.';
        } else {
            $ok = ThirdPartyStore::setRouteForUser($clientId, $serviceid, $type, $contactid, $isReceipt);
            if (! $ok) {
                return $this->alert('danger', 'Μη έγκυρη επαφή ή υπηρεσία.').$this->index($link, $clientId);
            }
            $msg = 'Η δρομολόγηση αποθηκεύτηκε.';
        }

        return $this->alert('success', $msg).$this->index($link, $clientId);
    }

    private function field(string $name, string $label, string $value, bool $required = false): string
    {
        $req = $required ? ' required' : '';

        return '<div class="form-group"><label class="col-sm-3 control-label">'.$label.'</label>'
            .'<div class="col-sm-9"><input class="form-control" name="'.$name.'" value="'.$value.'"'.$req.'></div></div>';
    }

    /** Inject selected="selected" into the matching <option> of a prebuilt set. */
    private function optionsWithSelected(string $options, int $selectedId): string
    {
        $needle = 'value="'.$selectedId.'"';

        return str_replace($needle.'>', $needle.' selected>', $options);
    }

    private function alert(string $type, string $msg): string
    {
        return '<div class="alert alert-'.$type.'">'.htmlspecialchars($msg).'</div>';
    }

    private function backButton(string $link): string
    {
        return '<a class="btn btn-default" href="'.$link.'">Επιστροφή</a>';
    }

    private function csrfField(): string
    {
        return function_exists('generate_token') ? (string) generate_token('plain') : '';
    }

    /**
     * Validate the CSRF token on a state-changing POST. Compares the posted
     * token against the value generate_token('plain') embeds RIGHT NOW — the
     * exact token WHMCS expects, regardless of which session key the installed
     * version stores it under (WHMCS 8.x moved it off $_SESSION['token'], which
     * broke a key-based check). The token is stable per session. Session-key
     * fallbacks cover older builds; no-helper builds aren't hard-blocked.
     */
    private function csrfValid(): bool
    {
        if (! function_exists('generate_token')) {
            return true;
        }
        $sent = (string) ($_POST['token'] ?? '');
        if ($sent === '') {
            return false;
        }
        if (preg_match('/value="([^"]+)"/', (string) generate_token('plain'), $m)
            && hash_equals($m[1], $sent)) {
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
}
