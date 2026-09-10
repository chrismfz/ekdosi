<?php

namespace App\Support\GoLive;

use App\Enums\MyDataMode;
use App\Models\Company;
use App\Models\CompanyBackupRun;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\ProductCategory;
use App\Models\VatCategory;
use App\Services\EInvoice\ProviderPreflight;
use App\Services\EInvoice\Transports\InvoSignDocument;
use App\Support\MyData\ClassificationGuidance;
use App\Support\MyData\Codes;
use App\Support\OperatorHealth\OperatorHealthReport;
use App\Support\Settings\SystemSettings;

/**
 * Cutover-readiness gates for ONE tenant — the automated, read-only half of the
 * go-live runbook (docs/go-live-runbook.md). Answers «μπορεί αυτός ο tenant να
 * εκδώσει πραγματικά παραστατικά;» WITHOUT terminal archaeology and WITHOUT
 * touching AADE or the legacy Firebird source.
 *
 * Severity model (kept clean on purpose):
 *   - FAIL  = a correctness / legal blocker — AADE would reject, or the document
 *             can't be numbered/issued correctly. Flips overall → not_ready.
 *   - WARN  = operational/advisory — issuing still works, but something needs an
 *             operator's eye (sandbox mode, no backups, dead queue worker).
 *   - SKIP  = not applicable to this tenant's e-invoicing provider (the myDATA
 *             gates are skipped for ee-peppol / gr-provider / none).
 *
 * What it deliberately does NOT do (→ runbook, manual): the Firebird `.fbk`
 * dead-code/usage probes (source DB isn't on this host) and a real AADE
 * production submit (files a legally real document — must stay human-gated).
 *
 * Reuses: App\Support\MyData\Codes (§8 tables), Company's provider/credential
 * predicates, and OperatorHealthReport for the infra slice (no duplication).
 */
class GoLiveCheckReport
{
    /** Drift above this (€) is real, not the legacy TCurrency 2dp rounding. The
     *  documented tolerance is a single off-by-€0.01 invoice-discount row, so the
     *  WARN band is ≤ 1 cent; anything strictly above is a FAIL (a 1.4-cent drift
     *  must NOT pass a legal cutover gate). The epsilon absorbs float noise. */
    private const DRIFT_TOLERANCE = 0.0101;

    /** OPS-15: last successful per-tenant backup older than this (days) → WARN at
     *  cutover. Generous vs daily/weekly cadences; a cutover check runs rarely, so
     *  it nudges «take a FRESH backup + restore drill right before going live». */
    private const BACKUP_STALE_DAYS = 8;

    public function __construct(private OperatorHealthReport $health) {}

    /**
     * @return array{tenant:array{id:int,name:string,slug:?string,provider:?string},
     *     generated_at:string, gates:list<array{key:string,label:string,status:string,detail:string}>,
     *     summary:array{pass:int,warn:int,fail:int,skip:int}, overall:string}
     */
    public function build(Company $tenant): array
    {
        $isGrMyData = $tenant->einvoice_provider === 'gr-mydata';
        $isGrProvider = $tenant->einvoice_provider === 'gr-provider';
        // Both direct-myDATA and ΥΠΑΗΕΣ-provider tenants emit AADE documents, so
        // the document-STRUCTURE gates (invoice types, VAT→AADE) apply to both;
        // only the TRANSPORT gates differ (direct creds/mode vs provider-live).
        $filesToAade = $isGrMyData || $isGrProvider;

        $gates = [
            $this->providerGate($tenant),
            $this->invoiceTypesGate($tenant, $filesToAade),
            $this->classificationPolicyGate($tenant, $filesToAade),
            $this->vatDefaultGate($tenant),
            $this->vatRatesGate($tenant, $filesToAade),
            $this->productionCredsGate($tenant, $isGrMyData),
            $this->modeGate($tenant, $isGrMyData),
            $this->providerLiveGate($tenant, $isGrProvider),
            $this->issuerAfmGate($tenant, $filesToAade),
            $this->issuerFieldsGate($tenant, $isGrProvider),
            $this->numberingGate($tenant),
            $this->driftGate($tenant),
            $this->backupGate($tenant),
            $this->globalBackupGate(),
            $this->secretsAtRestGate(),
            ...$this->infraGates(),
        ];

        $summary = ['pass' => 0, 'warn' => 0, 'fail' => 0, 'skip' => 0];
        foreach ($gates as $g) {
            $summary[$g['status']]++;
        }

        return [
            'tenant' => [
                'id' => (int) $tenant->id,
                'name' => (string) $tenant->name,
                'slug' => $tenant->slug,
                'provider' => $tenant->einvoice_provider,
            ],
            'generated_at' => now()->toIso8601String(),
            'gates' => $gates,
            'summary' => $summary,
            'overall' => $summary['fail'] === 0 ? 'ready' : 'not_ready',
        ];
    }

    // ── Gates ───────────────────────────────────────────────────────────────

    /** @return array{key:string,label:string,status:string,detail:string} */
    private function providerGate(Company $tenant): array
    {
        $p = $tenant->einvoice_provider;
        if (empty($p)) {
            return $this->gate('provider', 'Πάροχος e-invoicing', 'fail', 'δεν έχει οριστεί einvoice_provider');
        }

        return $this->gate('provider', 'Πάροχος e-invoicing', 'pass', "provider: {$p}");
    }

    /** @return array{key:string,label:string,status:string,detail:string} */
    private function invoiceTypesGate(Company $tenant, bool $filesToAade): array
    {
        if (! $filesToAade) {
            return $this->gate('invoice_types', 'Τύποι παραστατικών (ΑΑΔΕ)', 'skip', 'δεν υποβάλλει σε ΑΑΔΕ');
        }

        $types = InvoiceType::query()->where('company_id', $tenant->id)->get();
        $filable = $types->filter(fn (InvoiceType $t) => ! empty($t->mydata_type));

        if ($filable->isEmpty()) {
            return $this->gate('invoice_types', 'Τύποι παραστατικών (ΑΑΔΕ)', 'fail',
                'κανένας τύπος με mydata_type — δεν μπορεί να εκδοθεί στην ΑΑΔΕ');
        }

        $bad = [];
        foreach ($filable as $t) {
            $mt = $t->mydata_type;
            if (! Codes::invoiceTypeExists($mt)) {
                $bad[] = "[{$t->code}] mydata_type '{$mt}' άκυρο ([223])";

                continue;
            }
            if (Codes::isIncomeInvoiceType($mt)) {
                if (empty($t->mydata_income_class) || ! Codes::isValidIncomeClassType($t->mydata_income_class)) {
                    $bad[] = "[{$t->code}] λείπει/άκυρη κατηγορία εσόδων E3 ([230])";
                } elseif (empty($t->mydata_income_class_category) || ! Codes::isValidIncomeClassCategory($t->mydata_income_class_category)) {
                    $bad[] = "[{$t->code}] λείπει/άκυρη κατηγορία classification ([230])";
                }
            }
        }

        if ($bad !== []) {
            return $this->gate('invoice_types', 'Τύποι παραστατικών (ΑΑΔΕ)', 'fail', implode(' · ', $bad));
        }

        return $this->gate('invoice_types', 'Τύποι παραστατικών (ΑΑΔΕ)', 'pass',
            "{$filable->count()} τύπος/οι έτοιμοι για ΑΑΔΕ");
    }

    /**
     * MYD-006: the tenant's income-classification policy must be a CONSCIOUS
     * choice before a live filing. Goods lines file under §8.6 category1_1
     * (εμπορεύματα) or category1_2 (δικά μας προϊόντα) depending on whether the
     * entity resells or manufactures — there is no correct default, so an unchosen
     * `business_activity_type` FAILS the cutover (existing tenants included: pick
     * «Υπηρεσίες» for a services business — a 10-second review, not a code change).
     * A `mixed` tenant PASSES the selection gate but is reminded to classify its
     * goods per product-category (the per-category detail is guidance, not a hard
     * block — an all-services product set legitimately needs no override).
     *
     * @return array{key:string,label:string,status:string,detail:string}
     */
    private function classificationPolicyGate(Company $tenant, bool $filesToAade): array
    {
        $label = 'Πολιτική κατηγοριοποίησης εσόδων';

        if (! $filesToAade) {
            return $this->gate('classification_policy', $label, 'skip', 'δεν υποβάλλει σε ΑΑΔΕ');
        }

        $type = $tenant->business_activity_type;
        if (! ClassificationGuidance::isValid($type)) {
            return $this->gate('classification_policy', $label, 'fail',
                'δεν έχει επιλεγεί είδος δραστηριότητας (μεταπωλητής/παραγωγός/υπηρεσίες/μικτή) — ορίζει την '
                .'κατηγορία εσόδων §8.6 των αγαθών (εμπορεύματα=category1_1 vs προϊόντα=category1_2)· χωρίς '
                .'αυτό η ταξινόμηση αγαθών είναι εικασία. Όρισέ το στη «Ρυθμίσεις εταιρείας».');
        }

        // A `mixed` tenant PASSES the selection gate (the required act is done) but
        // is reminded to classify its GOODS categories per product-category. We do
        // NOT WARN on a count of null-override categories: a services category is
        // legitimately null (it files category1_3 from the type), so counting all
        // null overrides would fire on essentially every mixed tenant — noise, not
        // signal. The reminder is in the detail; the per-category form is where the
        // operator acts.
        if (ClassificationGuidance::requiresPerCategoryConfig($type)) {
            $classifiedGoods = ProductCategory::query()
                ->where('company_id', $tenant->id)
                ->whereIn('mydata_income_class_category', ['category1_1', 'category1_2'])
                ->exists();

            return $this->gate('classification_policy', $label, 'pass',
                'μικτή δραστηριότητα'.($classifiedGoods ? '' : ' — θύμισε: όρισε κατηγορία εσόδων στις κατηγορίες '
                    .'ΑΓΑΘΩΝ (Setup → Κατηγορίες προϊόντων· εμπορεύματα=category1_1, δικά μας προϊόντα=category1_2· '
                    .'οι υπηρεσίες μένουν category1_3 από τον τύπο)'));
        }

        return $this->gate('classification_policy', $label, 'pass',
            ClassificationGuidance::labelFor($type) ?? (string) $type);
    }

    /** @return array{key:string,label:string,status:string,detail:string} */
    private function vatDefaultGate(Company $tenant): array
    {
        $has = VatCategory::query()->where('company_id', $tenant->id)->where('is_default', true)->exists();

        return $has
            ? $this->gate('vat_default', 'Προεπιλεγμένο ΦΠΑ', 'pass', 'ορισμένο')
            : $this->gate('vat_default', 'Προεπιλεγμένο ΦΠΑ', 'fail', 'δεν υπάρχει default VatCategory');
    }

    /** @return array{key:string,label:string,status:string,detail:string} */
    private function vatRatesGate(Company $tenant, bool $filesToAade): array
    {
        if (! $filesToAade) {
            return $this->gate('vat_rates', 'Συντελεστές ΦΠΑ → ΑΑΔΕ', 'skip', 'δεν υποβάλλει σε ΑΑΔΕ');
        }

        $vats = VatCategory::query()->where('company_id', $tenant->id)->get();
        if ($vats->isEmpty()) {
            return $this->gate('vat_rates', 'Συντελεστές ΦΠΑ → ΑΑΔΕ', 'fail', 'δεν υπάρχουν VAT categories');
        }

        $errors = [];
        $warns = [];
        foreach ($vats as $v) {
            $rate = (float) $v->rate;
            if ($rate == 0.0) {
                $warns[] = '0% → χρειάζεται vatExemptionCategory ([217])';

                continue;
            }
            $matches = Codes::vatCategoriesForRate($rate);
            if (empty($matches)) {
                $errors[] = "{$rate}% → καμία AADE κατηγορία (§8.2)";
            } elseif (count($matches) > 1) {
                $warns[] = "{$rate}% ασαφές (".implode('/', $matches).')';
            }
        }

        if ($errors !== []) {
            return $this->gate('vat_rates', 'Συντελεστές ΦΠΑ → ΑΑΔΕ', 'fail', implode(' · ', $errors));
        }
        if ($warns !== []) {
            return $this->gate('vat_rates', 'Συντελεστές ΦΠΑ → ΑΑΔΕ', 'warn', implode(' · ', $warns));
        }

        return $this->gate('vat_rates', 'Συντελεστές ΦΠΑ → ΑΑΔΕ', 'pass', 'όλοι αντιστοιχούν σε AADE κατηγορία');
    }

    /** @return array{key:string,label:string,status:string,detail:string} */
    private function productionCredsGate(Company $tenant, bool $isGrMyData): array
    {
        if (! $isGrMyData) {
            return $this->gate('mydata_prod_creds', 'Διαπιστευτήρια ΑΑΔΕ (production)', 'skip', 'μη-myDATA tenant');
        }

        [$id, $key] = $tenant->mydataCredentials(MyDataMode::Production);

        return (! empty($id) && ! empty($key))
            ? $this->gate('mydata_prod_creds', 'Διαπιστευτήρια ΑΑΔΕ (production)', 'pass', 'παρόντα')
            : $this->gate('mydata_prod_creds', 'Διαπιστευτήρια ΑΑΔΕ (production)', 'fail',
                'λείπουν production aade-user-id / subscription-key');
    }

    /** @return array{key:string,label:string,status:string,detail:string} */
    private function modeGate(Company $tenant, bool $isGrMyData): array
    {
        if (! $isGrMyData) {
            return $this->gate('mydata_mode', 'Λειτουργία myDATA', 'skip', 'μη-myDATA tenant');
        }

        return match ($tenant->mydata_mode_enum) {
            MyDataMode::Off => $this->gate('mydata_mode', 'Λειτουργία myDATA', 'fail', 'Off — δεν θα υποβληθεί τίποτα'),
            MyDataMode::Sandbox => $this->gate('mydata_mode', 'Λειτουργία myDATA', 'warn', 'Sandbox — δείχνει ακόμα στο δοκιμαστικό'),
            MyDataMode::Production => $this->gate('mydata_mode', 'Λειτουργία myDATA', 'pass', 'Production'),
        };
    }

    /**
     * Transport readiness for a ΥΠΑΗΕΣ-provider (gr-provider) tenant — the
     * provider does the submitting, so its own mode/key must be live (the direct
     * mydata_prod_creds/mode gates SKIP for these tenants). Without this, a
     * gr-provider tenant with no provider config would falsely read READY.
     * Checks mode + provider key only — the encrypted provider_config and its
     * live validity are the runbook's manual smoke-test (no decryption here, so
     * a rotated APP_KEY never crashes the gate).
     *
     * @return array{key:string,label:string,status:string,detail:string}
     */
    private function providerLiveGate(Company $tenant, bool $isGrProvider): array
    {
        if (! $isGrProvider) {
            return $this->gate('provider_live', 'Πάροχος ΥΠΑΗΕΣ (live)', 'skip', 'μη-provider tenant');
        }

        $mode = (string) ($tenant->einvoice_provider_mode ?? 'off');
        if ($mode === 'off') {
            return $this->gate('provider_live', 'Πάροχος ΥΠΑΗΕΣ (live)', 'fail', 'einvoice_provider_mode = off');
        }

        $key = trim((string) $tenant->einvoice_provider_key);
        if ($key === '') {
            return $this->gate('provider_live', 'Πάροχος ΥΠΑΗΕΣ (live)', 'fail', 'δεν έχει οριστεί provider (einvoice_provider_key)');
        }

        // DELEGATE to ProviderPreflight::transportReadiness() rather than re-implement:
        // it already checks that the key resolves to a real transport AND that the
        // credentials for the ACTIVE environment are present (demo_* vs production split)
        // AND that the endpoint is a safe public https host. Re-doing only the first of
        // those here is what let a tenant with an EMPTY credential blob read as
        // cutover-ready — the same class of false green this gate exists to close — and a
        // second copy would drift from the Console's verdict on the same tenant.
        //
        // transportReadiness(), not audit(): audit() also covers issuer ΑΦΜ, issuer
        // fields and document types, each of which is ALREADY its own go-live gate —
        // swallowing them here would report another gate's failure under this one's name.
        //
        // One deliberate difference, not a drift: preflight calls mode=off «staged»
        // (warn); for a CUTOVER gate it is a fail, handled above before we get here.
        $failed = array_values(array_filter(
            app(ProviderPreflight::class)->transportReadiness($tenant),
            fn (array $check): bool => $check['status'] === 'fail',
        ));

        if ($failed !== []) {
            return $this->gate('provider_live', 'Πάροχος ΥΠΑΗΕΣ (live)', 'fail',
                implode(' · ', array_map(fn (array $c): string => $c['label'].': '.$c['detail'], $failed)));
        }

        // Sandbox is not cutover-ready: the documents go to the provider's DEMO
        // environment and never reach the real ΑΑΔΕ. Mirrors the mydata_mode gate,
        // which already warns for sandbox — the provider path had no signal at all
        // and reported a green «ready». The MODE IS NAMED so an invalid value
        // ('prod', 'live' — the column is a plain string with no enum cast, and
        // ProviderCredentials silently downgrades anything ≠ 'production' to sandbox)
        // is distinguishable from a tenant legitimately still on sandbox.
        if ($mode !== 'production') {
            return $this->gate('provider_live', 'Πάροχος ΥΠΑΗΕΣ (live)', 'warn',
                "{$key} (mode={$mode}) — δοκιμαστικό περιβάλλον παρόχου· τα παραστατικά δεν φτάνουν στην πραγματική ΑΑΔΕ");
        }

        return $this->gate('provider_live', 'Πάροχος ΥΠΑΗΕΣ (live)', 'pass', "{$key} ({$mode})");
    }

    /**
     * PROV-005: the issuer ΑΦΜ is the AADE-core issuer identity (`Issuer.vatNumber`)
     * that EVERY AADE-filing tenant needs — direct-myDATA and provider alike — and no
     * other go-live gate validated it (the credential gates check the AADE USER id /
     * provider key, not the company ΑΦΜ). A blank one is a wire-level rejection on the
     * first real document, so it FAILS the cutover.
     *
     * @return array{key:string,label:string,status:string,detail:string}
     */
    private function issuerAfmGate(Company $tenant, bool $filesToAade): array
    {
        $label = 'ΑΦΜ εκδότη';
        if (! $filesToAade) {
            return $this->gate('issuer_afm', $label, 'skip', 'δεν υποβάλλει σε ΑΑΔΕ');
        }

        return blank($tenant->afm)
            ? $this->gate('issuer_afm', $label, 'fail', 'λείπει το ΑΦΜ της εταιρείας — η ΑΑΔΕ απαιτεί issuer vatNumber')
            : $this->gate('issuer_afm', $label, 'pass', (string) $tenant->afm);
    }

    /**
     * PROV-005: a gr-provider tenant must ALSO carry the full issuer identity
     * InvoSign's <API_Issuer> EXTENSION requires (επωνυμία/ΚΑΔ/ΔΟΥ/διεύθυνση) — the
     * fields the direct-myDATA path never sends. Missing any is a wire-level rejection
     * on the first real document, so it FAILS the cutover. The ΑΦΜ is validated
     * separately (issuerAfmGate — it belongs to the AADE core, not this extension).
     * Contact fields (email/phone) are advisory (WARN) — the provider likely uses them
     * to deliver the document to the customer. Shares
     * InvoSignDocument::missingIssuerLabels with the Πάροχος Console preflight (single
     * source, no drift).
     *
     * @return array{key:string,label:string,status:string,detail:string}
     */
    private function issuerFieldsGate(Company $tenant, bool $isGrProvider): array
    {
        $label = 'Στοιχεία εκδότη (πάροχος)';

        if (! $isGrProvider) {
            return $this->gate('provider_issuer', $label, 'skip', 'μη-provider tenant');
        }

        $missing = InvoSignDocument::missingIssuerLabels($tenant);

        if ($missing['required'] !== []) {
            return $this->gate('provider_issuer', $label, 'fail',
                'λείπουν υποχρεωτικά πεδία που απαιτεί ο πάροχος: '.implode(', ', $missing['required']));
        }
        if ($missing['recommended'] !== []) {
            return $this->gate('provider_issuer', $label, 'warn',
                'λείπει (συνιστάται — ο πάροχος πιθανώς για αποστολή στον πελάτη): '.implode(', ', $missing['recommended']));
        }

        return $this->gate('provider_issuer', $label, 'pass', 'πλήρη στοιχεία εκδότη');
    }

    /** @return array{key:string,label:string,status:string,detail:string} */
    private function numberingGate(Company $tenant): array
    {
        $types = InvoiceType::query()->where('company_id', $tenant->id)->get();
        if ($types->isEmpty()) {
            return $this->gate('numbering', 'Αρίθμηση', 'fail', 'δεν υπάρχουν τύποι παραστατικών — αδύνατη η έκδοση');
        }

        $emptyCode = $types->filter(fn (InvoiceType $t) => empty($t->code));
        $dupCodes = $types->pluck('code')->filter()->duplicates();
        // null OR negative → GET_INV_CODE (code||invcount, no floor) would emit a
        // malformed/negative ΑΑ that AADE rejects. 0 is fine (unused → starts at 1).
        $badCount = $types->filter(fn (InvoiceType $t) => $t->invcount === null || (int) $t->invcount < 0);

        $problems = [];
        if ($emptyCode->isNotEmpty()) {
            $problems[] = 'τύπος χωρίς code';
        }
        if ($dupCodes->isNotEmpty()) {
            $problems[] = 'διπλό code: '.$dupCodes->implode(', ');
        }
        if ($badCount->isNotEmpty()) {
            $problems[] = 'invcount μη ορισμένο / αρνητικό σε κάποιους τύπους';
        }

        if ($problems !== []) {
            return $this->gate('numbering', 'Αρίθμηση', 'fail', implode(' · ', $problems));
        }

        return $this->gate('numbering', 'Αρίθμηση', 'pass',
            "{$types->count()} τύποι, μοναδικά codes, μετρητές ΟΚ");
    }

    /**
     * Golden totals check — read-only recompute of net_total/gross_total from the
     * lines (mirrors RecomputeInvoiceTotals' formula) vs the stored cache. A
     * handful of ~1-cent rows are the documented TCurrency-rounding tolerance
     * (WARN); anything larger means the import/edit math drifted (FAIL). NEVER
     * saves.
     *
     * KEEP IN SYNC with App\Services\RecomputeInvoiceTotals (the canonical, but
     * mutating, formula): `round(Σ line.net_price × (1 − header_discount/100), 2)`.
     * If that rounding shape changes, change it here too — a divergent copy would
     * report phantom drift (false FAIL) or hide real drift (false PASS).
     *
     * @return array{key:string,label:string,status:string,detail:string}
     */
    private function driftGate(Company $tenant): array
    {
        $over = 0;
        $cent = 0;
        $checked = 0;

        Invoice::query()
            ->where('company_id', $tenant->id)
            ->with('lines:id,invoice_id,net_price,gross_price')
            ->chunkById(500, function ($chunk) use (&$over, &$cent, &$checked): void {
                foreach ($chunk as $inv) {
                    $checked++;
                    $factor = 1 - ((float) $inv->header_discount_percent / 100);
                    $expNet = round($inv->lines->sum(fn ($l) => (float) $l->net_price) * $factor, 2);
                    $expGross = round($inv->lines->sum(fn ($l) => (float) $l->gross_price) * $factor, 2);
                    $drift = max(
                        abs($expNet - (float) $inv->net_total),
                        abs($expGross - (float) $inv->gross_total),
                    );
                    if ($drift > self::DRIFT_TOLERANCE) {
                        $over++;
                    } elseif ($drift > 0.0001) {
                        $cent++;
                    }
                }
            });

        if ($checked === 0) {
            return $this->gate('totals_drift', 'Έλεγχος αθροισμάτων', 'pass', 'δεν υπάρχουν παραστατικά ακόμα');
        }
        if ($over > 0) {
            return $this->gate('totals_drift', 'Έλεγχος αθροισμάτων', 'fail',
                "{$over} παραστατικά με απόκλιση > 1 λεπτό (από {$checked})");
        }
        if ($cent > 0) {
            return $this->gate('totals_drift', 'Έλεγχος αθροισμάτων', 'warn',
                "{$cent} παραστατικά με απόκλιση ~1 λεπτό (αναμενόμενο σε εκπτώσεις)");
        }

        return $this->gate('totals_drift', 'Έλεγχος αθροισμάτων', 'pass', "{$checked} παραστατικά, καμία απόκλιση");
    }

    /** @return array{key:string,label:string,status:string,detail:string} */
    /**
     * OPS-15: «enabled» alone is not DR readiness — a toggle that has never
     * actually produced a backup, or whose last success is stale, is a false
     * sense of safety at cutover. So beyond the flag we require EVIDENCE of a
     * recent successful run (`company_backup_runs.status = ok`). No successful
     * run ever, or the last one older than the stale window, stays WARN and
     * nudges the operator to run a fresh backup + a restore DRILL (see
     * docs/updates-runbook.md §Restore drill) before go-live. Explicit
     * company_id (CLI: no ambient tenant context).
     */
    private function backupGate(Company $tenant): array
    {
        $bs = $tenant->backupSetting;
        if ($bs === null || ! $bs->enabled) {
            return $this->gate('backup', 'Αντίγραφα ασφαλείας', 'warn', 'δεν είναι ενεργά για αυτόν τον tenant');
        }

        $lastOk = CompanyBackupRun::query()
            ->where('company_id', $tenant->id)
            ->where('status', 'ok')
            ->whereNotNull('finished_at')
            ->latest('finished_at')
            ->first();

        if ($lastOk === null) {
            return $this->gate('backup', 'Αντίγραφα ασφαλείας', 'warn',
                "ενεργά ({$bs->frequency}) αλλά ΚΑΜΙΑ επιτυχημένη εκτέλεση ακόμα — τρέξε δοκιμαστικό backup + restore drill πριν το go-live (docs/updates-runbook.md)");
        }

        $ageDays = (int) $lastOk->finished_at->diffInDays(now());
        if ($ageDays > self::BACKUP_STALE_DAYS) {
            return $this->gate('backup', 'Αντίγραφα ασφαλείας', 'warn',
                "ενεργά ({$bs->frequency})· τελευταίο επιτυχημένο backup πριν {$ageDays} ημ. — επιβεβαίωσε πρόσφατο backup + restore drill πριν το go-live");
        }

        return $this->gate('backup', 'Αντίγραφα ασφαλείας', 'pass',
            "ενεργά ({$bs->frequency})· τελευταίο επιτυχημένο: {$lastOk->finished_at->format('d/m/Y H:i')}");
    }

    /**
     * SEC-1 (AUDIT): per-tenant secrets (myDATA/WHMCS/SMTP/GSIS creds) are stored
     * PLAINTEXT at rest unless EKDOSI_ENCRYPT_SECRETS_AT_REST is on — a deliberate
     * DR trade-off, but one that must be a CONSCIOUS choice, not a silent default.
     * PASS when encrypted, PASS when plaintext is explicitly acknowledged
     * (EKDOSI_SECRETS_PLAINTEXT_ACKNOWLEDGED), WARN otherwise so cutover forces
     * the decision. Global (not per-tenant), but reported per run for visibility.
     *
     * @return array{key:string,label:string,status:string,detail:string}
     */
    private function secretsAtRestGate(): array
    {
        if ((bool) config('ekdosi.secrets.encrypt_at_rest')) {
            return $this->gate('secrets_at_rest', 'Μυστικά at-rest', 'pass', 'κρυπτογραφημένα (APP_KEY)');
        }

        if ((bool) config('ekdosi.secrets.plaintext_acknowledged')) {
            return $this->gate('secrets_at_rest', 'Μυστικά at-rest', 'pass',
                'plaintext — αποδεκτό ρητά (EKDOSI_SECRETS_PLAINTEXT_ACKNOWLEDGED· βλ. docs/security-at-rest.md)');
        }

        return $this->gate('secrets_at_rest', 'Μυστικά at-rest', 'warn',
            'plaintext χωρίς ρητή αποδοχή — όρισε EKDOSI_ENCRYPT_SECRETS_AT_REST=true (+ secrets:reencrypt) '
            .'ή EKDOSI_SECRETS_PLAINTEXT_ACKNOWLEDGED=true (βλ. docs/security-at-rest.md)');
    }

    /**
     * AUDIT OPS-1: the WHOLE-DB spatie backup (all tenants + files) is the
     * disaster-recovery floor under the per-tenant exports — a disk loss with
     * this off (or local-only) loses every tenant's books. Same enable
     * resolution as routes/console.php: the «Ρυθμίσεις χρονοπρογραμματιστή»
     * DB override wins, the env flag is the default.
     *
     * @return array{key:string,label:string,status:string,detail:string}
     */
    private function globalBackupGate(): array
    {
        $enabled = app(SystemSettings::class)
            ->bool('schedule.backup_run_enabled', (bool) config('ekdosi.schedule.backup_run_enabled'));

        if (! $enabled) {
            return $this->gate('backup_global', 'Καθολικό αντίγραφο ΒΔ', 'warn',
                'το backup:run είναι απενεργοποιημένο (EKDOSI_SCHEDULE_BACKUP_RUN / Ρυθμίσεις χρονοπρογραμματιστή)');
        }

        $disks = (array) config('backup.backup.destination.disks', []);
        $offsite = array_values(array_diff($disks, ['local']));
        if ($offsite === []) {
            return $this->gate('backup_global', 'Καθολικό αντίγραφο ΒΔ', 'warn',
                'μόνο τοπικός προορισμός — χάνεται μαζί με το VM (πρόσθεσε off-site disk στο BACKUP_DESTINATION_DISKS)');
        }

        return $this->gate('backup_global', 'Καθολικό αντίγραφο ΒΔ', 'pass',
            'ενεργό, προορισμοί: '.implode(', ', $disks));
    }

    /**
     * Infra slice — delegated to OperatorHealthReport (no duplication). These are
     * WARN, not FAIL: issuing + myDATA submit are SYNCHRONOUS, so a dead worker
     * doesn't block go-live — it only delays auto-email / backups / reconcile.
     *
     * @return list<array{key:string,label:string,status:string,detail:string}>
     */
    private function infraGates(): array
    {
        // Only the queue slice is needed — call it directly rather than build(),
        // which also runs disk() (recursive storage/logs/backups walk) + the
        // per-tenant mail/whmcs/mydata queries this read-only gate has no use for.
        $queue = $this->health->queue();
        $gates = [];

        // OPS-001: the OS cron (scheduler tick) — proved independently of the worker.
        // WARN, not FAIL: issuing + myDATA submit are synchronous, so a dead cron
        // doesn't block go-live, but it silently stops backups/reconcile/auto-email.
        $cron = $this->health->cron();
        $cronStatus = $cron['status'] ?? 'missing';
        $gates[] = $cronStatus === 'ok'
            ? $this->gate('cron', 'Χρονοπρογραμματιστής (cron)', 'pass', 'ζωντανός')
            : $this->gate('cron', 'Χρονοπρογραμματιστής (cron)', 'warn',
                ($cronStatus === 'missing' ? 'το schedule:run δεν έχει τρέξει' : 'stale (>10 λεπτά)')
                .' — backups/reconcile/email δεν θα τρέξουν· τρέξε «php artisan ops:cron»');

        $hb = $queue['worker_heartbeat_status'] ?? 'missing';
        $gates[] = $hb === 'ok'
            ? $this->gate('queue_worker', 'Queue worker', 'pass', 'ζωντανός')
            : $this->gate('queue_worker', 'Queue worker', 'warn',
                $hb === 'missing' ? 'δεν τρέχει (καμία σφυγμομέτρηση) — email/backups/reconcile δεν θα τρέξουν' : 'stale (>10 λεπτά)');

        $failed = (int) ($queue['failed_jobs'] ?? 0);
        if ($failed > 0) {
            $gates[] = $this->gate('failed_jobs', 'Αποτυχημένα jobs', 'warn', "{$failed} στην ουρά failed_jobs");
        }

        return $gates;
    }

    /** @return array{key:string,label:string,status:string,detail:string} */
    private function gate(string $key, string $label, string $status, string $detail): array
    {
        return ['key' => $key, 'label' => $label, 'status' => $status, 'detail' => $detail];
    }
}
