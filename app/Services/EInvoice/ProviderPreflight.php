<?php

namespace App\Services\EInvoice;

use App\Enums\MyDataMode;
use App\Models\Company;
use App\Models\InvoiceType;
use App\Services\EInvoice\Transports\InvoSignDocument;
use App\Services\EInvoice\Transports\NullProviderTransport;
use App\Support\EInvoice\ProviderEndpointGuard;
use RuntimeException;

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

        // Provider credentials present — for the ACTIVE environment only. Where a
        // provider splits sandbox vs production creds with a `demo_` prefix (InvoSign:
        // demo_base_url/demo_token vs base_url/token), check the set the current mode
        // actually uses (same split as InvoSignTransport::resolve), so a production
        // tenant with only sandbox creds filled fails the preflight instead of a
        // misleading green. Providers with no split (e.g. SBZ) check all fields.
        $defs = (array) config("ekdosi.einvoice.provider_fields.{$key}", []);
        $fields = array_keys($defs);
        $config = is_array($tenant->einvoice_provider_config) ? $tenant->einvoice_provider_config : [];
        $sandbox = ($tenant->einvoice_provider_mode ?? 'off') !== 'production';
        $hasDemoSplit = (bool) array_filter($fields, fn ($f) => str_starts_with($f, 'demo_'));
        $relevant = $hasDemoSplit
            ? array_values(array_filter($fields, fn ($f) => str_starts_with($f, 'demo_') === $sandbox))
            : $fields;
        $missing = array_values(array_filter($relevant, fn ($f) => ($config[$f] ?? '') === ''));
        $env = $sandbox ? 'δοκιμαστικού' : 'παραγωγής';

        if ($relevant !== [] && $missing === []) {
            $checks[] = ['status' => 'ok', 'label' => 'Στοιχεία παρόχου', 'detail' => "Συμπληρωμένα για το περιβάλλον {$env}."];
        } else {
            $checks[] = ['status' => 'fail', 'label' => 'Στοιχεία παρόχου', 'detail' => "Λείπουν στοιχεία του περιβάλλοντος {$env}: ".(implode(', ', $missing) ?: '—').'.'];
        }

        // Endpoint safety: any `url` endpoint field for the active environment must
        // be a public HTTPS host — never http/userinfo/query/internal (SSRF /
        // token+payload exfiltration, PROV-017). Only validate a present value; an
        // empty one is already flagged above.
        foreach (array_filter($relevant, fn ($f) => $defs[$f]['url'] ?? false) as $urlField) {
            $url = (string) ($config[$urlField] ?? '');
            if ($url === '') {
                continue;
            }
            try {
                ProviderEndpointGuard::assertSafeBaseUrl($url);
                $checks[] = ['status' => 'ok', 'label' => 'URL παρόχου', 'detail' => 'Έγκυρο δημόσιο https endpoint.'];
            } catch (RuntimeException $e) {
                $checks[] = ['status' => 'fail', 'label' => 'URL παρόχου', 'detail' => $e->getMessage()];
            }
        }

        // Issuer AFM (the AADE payload needs it). blank() — a whitespace-only ΑΦΜ is
        // as missing as an empty one (consistent with the issuer-field checks below).
        $checks[] = filled($tenant->afm)
            ? ['status' => 'ok', 'label' => 'ΑΦΜ εκδότη', 'detail' => (string) $tenant->afm]
            : ['status' => 'fail', 'label' => 'ΑΦΜ εκδότη', 'detail' => 'Λείπει το ΑΦΜ της εταιρείας.'];

        // PROV-005: InvoSign's <API_Issuer> extension requires the FULL issuer
        // identity, not just the ΑΦΜ (επωνυμία, ΚΑΔ, ΔΟΥ, οδός, Τ.Κ., πόλη). A tenant
        // missing any passes this preflight today and is rejected by the provider at
        // the wire on the first real document — the false green this closes. Single
        // source = InvoSignDocument::ISSUER_FIELDS (exactly what the payload carries).
        $missingIssuer = InvoSignDocument::missingIssuerLabels($tenant);
        $checks[] = $missingIssuer['required'] === []
            ? ['status' => 'ok', 'label' => 'Στοιχεία εκδότη (πάροχος)', 'detail' => 'Πλήρη (επωνυμία, ΚΑΔ, ΔΟΥ, διεύθυνση).']
            : ['status' => 'fail', 'label' => 'Στοιχεία εκδότη (πάροχος)', 'detail' => 'Λείπουν υποχρεωτικά πεδία που απαιτεί ο πάροχος: '.implode(', ', $missingIssuer['required']).'.'];

        // Contact fields (email/phone) are NOT required for acceptance, but the
        // provider likely uses them to deliver the document to the customer — nudge,
        // don't block. Shown only when actually missing.
        if ($missingIssuer['recommended'] !== []) {
            $checks[] = ['status' => 'warn', 'label' => 'Επικοινωνία εκδότη', 'detail' => 'Λείπει: '.implode(', ', $missingIssuer['recommended']).' — δεν εμποδίζει την έκδοση, αλλά ο πάροχος πιθανώς το χρησιμοποιεί για να στείλει το παραστατικό στον πελάτη.'];
        }

        // myDATA read-path creds — provider tenants still reconcile via myDATA.
        // Validate a COMPLETE pair (aade-id + subscription-key) for the ACTIVE read
        // environment (mydataReadMode), not a sandbox-id + prod-key mash-up that reads
        // green but cannot actually read (PROV-005). Stays a WARN: reconciliation is
        // not a filing blocker.
        $readMode = $tenant->mydataReadMode();
        if ($readMode === null) {
            $checks[] = ['status' => 'warn', 'label' => 'myDATA (έλεγχος/συμφωνία)', 'detail' => 'Δεν έχει οριστεί περιβάλλον/aade-id ανάγνωσης — η διασταύρωση/reconciliation δεν θα δουλεύει.'];
        } else {
            [, $readKey] = $tenant->mydataCredentials($readMode);
            $env = $readMode === MyDataMode::Production ? 'παραγωγής' : 'δοκιμαστικού';
            // filled(), not empty(): a whitespace-only key must not read green (and the
            // aade-id half is already guaranteed present by mydataReadMode()).
            // mydataReadMode() only returns a mode whose aade-id is filled, so the
            // missing half here is always the subscription-key.
            $checks[] = filled($readKey)
                ? ['status' => 'ok', 'label' => 'myDATA (έλεγχος/συμφωνία)', 'detail' => "Πλήρες ζεύγος διαπιστευτηρίων {$env}."]
                : ['status' => 'warn', 'label' => 'myDATA (έλεγχος/συμφωνία)', 'detail' => "Ελλιπές ζεύγος διαπιστευτηρίων {$env} (λείπει subscription-key) — η διασταύρωση δεν θα δουλεύει."];
        }

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
