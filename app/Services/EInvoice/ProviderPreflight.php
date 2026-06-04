<?php

namespace App\Services\EInvoice;

use App\Models\Company;
use App\Models\InvoiceType;
use App\Services\EInvoice\Transports\NullProviderTransport;

/**
 * Read-only readiness audit for a provider (ΥΠΑΗΕΣ) tenant — the «Πάροχος Console»
 * preflight (P4). NO network calls: it checks CONFIGURATION so gaps surface before
 * a real filing fails. Mirrors mydata:preflight's spirit for the provider path.
 *
 * Each check is ['status' => ok|warn|fail, 'label' => …, 'detail' => …].
 * fail = will not file correctly; warn = staged / non-blocking; ok = ready.
 */
class ProviderPreflight
{
    public function __construct(private readonly ProviderTransportRegistry $registry) {}

    /** @return list<array{status:string, label:string, detail:string}> */
    public function audit(Company $tenant): array
    {
        $checks = [];

        if ($tenant->einvoice_provider !== 'gr-provider') {
            return [[
                'status' => 'warn',
                'label' => 'Τρόπος αποστολής',
                'detail' => "Δεν στέλνει μέσω παρόχου (einvoice_provider='{$tenant->einvoice_provider}'). Ο έλεγχος αφορά παρόχους.",
            ]];
        }

        $key = (string) $tenant->einvoice_provider_key;
        $checks[] = [
            'status' => $key !== '' ? 'ok' : 'fail',
            'label' => 'Πάροχος',
            'detail' => $key !== '' ? $key : 'Δεν έχει επιλεγεί πάροχος (einvoice_provider_key κενό).',
        ];

        // Transport registered?
        $transport = $this->registry->for($key);
        $checks[] = $transport instanceof NullProviderTransport
            ? ['status' => 'fail', 'label' => 'Τεχνική σύνδεση', 'detail' => "Ο πάροχος «{$key}» δεν είναι ενεργοποιημένος (δεν υπάρχει transport στο config)."]
            : ['status' => 'ok', 'label' => 'Τεχνική σύνδεση', 'detail' => class_basename($transport)];

        // Environment / mode.
        $mode = $tenant->einvoice_provider_mode ?? 'off';
        $checks[] = $mode === 'off'
            ? ['status' => 'warn', 'label' => 'Περιβάλλον', 'detail' => 'mode=off — σε αναμονή (staged), δεν φιλάρει ακόμη.']
            : ['status' => 'ok', 'label' => 'Περιβάλλον', 'detail' => $mode];

        // Provider credentials present (any of the provider's configured fields).
        $fields = (array) config("ekdosi.einvoice.provider_fields.{$key}", []);
        $config = is_array($tenant->einvoice_provider_config) ? $tenant->einvoice_provider_config : [];
        $filled = 0;
        foreach (array_keys($fields) as $field) {
            if (($config[$field] ?? '') !== '') {
                $filled++;
            }
        }
        $checks[] = $filled === 0
            ? ['status' => 'fail', 'label' => 'Στοιχεία παρόχου', 'detail' => 'Δεν έχουν συμπληρωθεί διαπιστευτήρια παρόχου.']
            : ['status' => 'ok', 'label' => 'Στοιχεία παρόχου', 'detail' => "{$filled} πεδία συμπληρωμένα (επιβεβαίωσε αυτά του ενεργού περιβάλλοντος)."];

        // Issuer AFM (the AADE payload needs it).
        $checks[] = ($tenant->afm ?? '') !== ''
            ? ['status' => 'ok', 'label' => 'ΑΦΜ εκδότη', 'detail' => (string) $tenant->afm]
            : ['status' => 'fail', 'label' => 'ΑΦΜ εκδότη', 'detail' => 'Λείπει το ΑΦΜ της εταιρείας.'];

        // myDATA read-path creds — provider tenants still reconcile via myDATA.
        $hasMyData = ($tenant->mydata_aade_id_sandbox || $tenant->mydata_aade_id_production)
            && ($tenant->mydata_subscription_key_sandbox || $tenant->mydata_subscription_key_production);
        $checks[] = $hasMyData
            ? ['status' => 'ok', 'label' => 'myDATA (έλεγχος/συμφωνία)', 'detail' => 'Διαπιστευτήρια myDATA παρόντα (read path).']
            : ['status' => 'warn', 'label' => 'myDATA (έλεγχος/συμφωνία)', 'detail' => 'Λείπουν myDATA creds — η διασταύρωση/reconciliation δεν θα δουλεύει.'];

        // At least one invoice type mapped to a myDATA type.
        $typed = InvoiceType::query()->where('company_id', $tenant->id)->whereNotNull('mydata_type')->count();
        $checks[] = $typed > 0
            ? ['status' => 'ok', 'label' => 'Είδη παραστατικών', 'detail' => "{$typed} με mydata_type."]
            : ['status' => 'fail', 'label' => 'Είδη παραστατικών', 'detail' => 'Κανένα είδος παραστατικού με mydata_type — δεν μπορεί να εκδοθεί.'];

        return $checks;
    }

    /** True when no `fail` check is present. */
    public function isReady(Company $tenant): bool
    {
        foreach ($this->audit($tenant) as $check) {
            if ($check['status'] === 'fail') {
                return false;
            }
        }

        return true;
    }
}
