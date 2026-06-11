<?php

namespace App\Support\GoLive;

use App\Enums\MyDataMode;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\VatCategory;
use App\Support\MyData\Codes;
use App\Support\OperatorHealth\OperatorHealthReport;

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
    /** Drift above this (€) is real, not the legacy TCurrency 2dp rounding. */
    private const DRIFT_TOLERANCE = 0.015;

    public function __construct(private OperatorHealthReport $health) {}

    /**
     * @return array{tenant:array{id:int,name:string,slug:?string,provider:?string},
     *     generated_at:string, gates:list<array{key:string,label:string,status:string,detail:string}>,
     *     summary:array{pass:int,warn:int,fail:int,skip:int}, overall:string}
     */
    public function build(Company $tenant): array
    {
        $isGrMyData = $tenant->einvoice_provider === 'gr-mydata';

        $gates = [
            $this->providerGate($tenant, $isGrMyData),
            $this->invoiceTypesGate($tenant, $isGrMyData),
            $this->vatDefaultGate($tenant),
            $this->vatRatesGate($tenant, $isGrMyData),
            $this->productionCredsGate($tenant, $isGrMyData),
            $this->modeGate($tenant, $isGrMyData),
            $this->numberingGate($tenant),
            $this->driftGate($tenant),
            $this->backupGate($tenant),
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
    private function providerGate(Company $tenant, bool $isGrMyData): array
    {
        $p = $tenant->einvoice_provider;
        if (empty($p)) {
            return $this->gate('provider', 'Πάροχος e-invoicing', 'fail', 'δεν έχει οριστεί einvoice_provider');
        }

        return $this->gate('provider', 'Πάροχος e-invoicing', 'pass',
            "provider: {$p}".($isGrMyData ? '' : ' — οι έλεγχοι myDATA παραλείπονται'));
    }

    /** @return array{key:string,label:string,status:string,detail:string} */
    private function invoiceTypesGate(Company $tenant, bool $isGrMyData): array
    {
        if (! $isGrMyData) {
            return $this->gate('invoice_types', 'Τύποι παραστατικών (myDATA)', 'skip', 'μη-myDATA tenant');
        }

        $types = InvoiceType::query()->where('company_id', $tenant->id)->get();
        $filable = $types->filter(fn (InvoiceType $t) => ! empty($t->mydata_type));

        if ($filable->isEmpty()) {
            return $this->gate('invoice_types', 'Τύποι παραστατικών (myDATA)', 'fail',
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
            return $this->gate('invoice_types', 'Τύποι παραστατικών (myDATA)', 'fail', implode(' · ', $bad));
        }

        return $this->gate('invoice_types', 'Τύποι παραστατικών (myDATA)', 'pass',
            "{$filable->count()} τύπος/οι έτοιμοι για ΑΑΔΕ");
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
    private function vatRatesGate(Company $tenant, bool $isGrMyData): array
    {
        if (! $isGrMyData) {
            return $this->gate('vat_rates', 'Συντελεστές ΦΠΑ → ΑΑΔΕ', 'skip', 'μη-myDATA tenant');
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

    /** @return array{key:string,label:string,status:string,detail:string} */
    private function numberingGate(Company $tenant): array
    {
        $types = InvoiceType::query()->where('company_id', $tenant->id)->get();
        if ($types->isEmpty()) {
            return $this->gate('numbering', 'Αρίθμηση', 'fail', 'δεν υπάρχουν τύποι παραστατικών — αδύνατη η έκδοση');
        }

        $emptyCode = $types->filter(fn (InvoiceType $t) => empty($t->code));
        $dupCodes = $types->pluck('code')->filter()->duplicates();
        $nullCount = $types->filter(fn (InvoiceType $t) => $t->invcount === null);

        $problems = [];
        if ($emptyCode->isNotEmpty()) {
            $problems[] = 'τύπος χωρίς code';
        }
        if ($dupCodes->isNotEmpty()) {
            $problems[] = 'διπλό code: '.$dupCodes->implode(', ');
        }
        if ($nullCount->isNotEmpty()) {
            $problems[] = 'invcount μη ορισμένο σε κάποιους τύπους';
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
    private function backupGate(Company $tenant): array
    {
        $bs = $tenant->backupSetting;
        if ($bs === null || ! $bs->enabled) {
            return $this->gate('backup', 'Αντίγραφα ασφαλείας', 'warn', 'δεν είναι ενεργά για αυτόν τον tenant');
        }

        return $this->gate('backup', 'Αντίγραφα ασφαλείας', 'pass', "ενεργά ({$bs->frequency})");
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
        $queue = $this->health->build()['queue'] ?? [];
        $gates = [];

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
