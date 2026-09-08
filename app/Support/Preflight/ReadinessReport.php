<?php

namespace App\Support\Preflight;

use App\Models\Company;
use App\Models\DeliveryMethod;
use App\Models\DistributionAim;
use App\Models\InvoiceType;
use App\Models\MetricUnit;
use App\Models\PaymentMethod;
use App\Models\ProductCategory;
use App\Models\VatCategory;
use App\Models\WhmcsIncomeMap;
use App\Models\WhmcsPaymentMap;
use App\Services\MyData\MyDataConfigAudit;
use App\Support\Tenancy\CompanyContext;

/**
 * «Έλεγχος ετοιμότητας» — a cross-tenant production-readiness checklist: the
 * config-side twin of «Υγεία συστήματος» (which is liveness). It answers «είμαι
 * νόμιμος;» across every dimension a first document needs: myDATA config (types /
 * VAT / §8.3 / §8.12 — reusing {@see MyDataConfigAudit}, the SAME audit the
 * «Έλεγχος ρυθμίσεων» tab and `mydata:preflight` use), the seeded lookup tables,
 * product-category income classification (§8.6) and the optional WHMCS mappings.
 *
 * Read-only. Cross-tenant, but rendered inside the Filament panel where `TenantSet`
 * has pinned {@see CompanyContext} to the CURRENTLY-selected tenant — so a raw
 * `->where('company_id', $other)` would AND-combine with the ambient `CompanyScope`
 * and return zero rows for every OTHER company (and the reused {@see MyDataConfigAudit}
 * would see an empty tenant). We therefore build each company under
 * `CompanyContext::actAs($company, …)`, which re-pins the ambient scope to the target
 * so the explicit `company_id` filters and the global scope agree. Each section carries
 * a status (fail > warn > ok) and its rows; a company's status is the worst of its sections.
 */
class ReadinessReport
{
    private const RANK = ['fail' => 2, 'warn' => 1, 'ok' => 0];

    public function __construct(
        private MyDataConfigAudit $audit,
        private CompanyContext $context,
    ) {}

    /**
     * @return list<array{company_id:int, name:string, slug:string, status:string,
     *   sections: list<array{key:string, label:string, status:string, items: list<array{status:string, message:string}>}>}>
     */
    public function build(): array
    {
        return Company::query()->orderBy('name')->get()
            ->map(fn (Company $company) => $this->forCompany($company))
            ->all();
    }

    /**
     * @return array{company_id:int, name:string, slug:string, status:string,
     *   sections: list<array{key:string, label:string, status:string, items: list<array{status:string, message:string}>}>}
     */
    public function forCompany(Company $company): array
    {
        // Re-pin the ambient scope to THIS company (see class docblock): under the
        // panel's TenantSet the ambient tenant is whichever one the viewer selected,
        // so building any other company needs its own context or every scoped query
        // (and the reused audit) silently returns an empty tenant.
        return $this->context->actAs($company, function () use ($company): array {
            $sections = [
                $this->mydataSection($company),
                $this->lookupsSection($company),
                $this->productsSection($company),
            ];
            if ($company->hasWhmcsIntegration()) {
                $sections[] = $this->whmcsSection($company);
            }

            return [
                'company_id' => (int) $company->id,
                'name' => (string) $company->name,
                'slug' => (string) $company->slug,
                'status' => $this->worst(array_map(fn (array $s) => $s['status'], $sections)),
                'sections' => $sections,
            ];
        });
    }

    /** @return array{key:string, label:string, status:string, items: list<array{status:string, message:string}>} */
    private function mydataSection(Company $company): array
    {
        if (! $company->filesToAadeByProvider()) {
            return $this->section('mydata', 'Ρυθμίσεις myDATA', [
                $this->item('ok', 'Μη-AADE tenant («'.$company->einvoice_provider.'») — δεν απαιτείται config myDATA.'),
            ]);
        }

        $result = $this->audit->audit($company);
        $errors = $result->errorCount();
        $warns = $result->warnCount();

        $items = [];
        if ($errors > 0) {
            $items[] = $this->item('fail', $errors.' σφάλμα(τα) config — η ΑΑΔΕ θα απέρριπτε. Δες «Κονσόλα myDATA → Έλεγχος ρυθμίσεων».');
        }
        if ($warns > 0) {
            $items[] = $this->item('warn', $warns.' προειδοποίηση(εις) config (π.χ. §8.12 τρόποι πληρωμής, credentials).');
        }
        if ($errors === 0 && $warns === 0) {
            $items[] = $this->item('ok', 'Καθαρές (τύποι/ΦΠΑ/§8.3/§8.12) — η ΑΑΔΕ δεν έχει λόγο απόρριψης.');
        }

        return $this->section('mydata', 'Ρυθμίσεις myDATA', $items);
    }

    /** @return array{key:string, label:string, status:string, items: list<array{status:string, message:string}>} */
    private function lookupsSection(Company $company): array
    {
        /** @var list<array{0:string, 1:class-string}> $checks */
        $checks = [
            ['Κατηγορίες ΦΠΑ', VatCategory::class],
            ['Τύποι παραστατικών', InvoiceType::class],
            ['Τρόποι πληρωμής', PaymentMethod::class],
            ['Μονάδες μέτρησης', MetricUnit::class],
            ['Τρόποι αποστολής', DeliveryMethod::class],
            ['Σκοποί διακίνησης', DistributionAim::class],
            ['Κατηγορίες προϊόντων', ProductCategory::class],
        ];

        $items = [];
        foreach ($checks as [$label, $model]) {
            $count = $model::query()->where('company_id', $company->getKey())->count();
            $items[] = $count > 0
                ? $this->item('ok', $label.': '.$count)
                : $this->item('warn', $label.': κανένα — auto-seeded σε νέα εγκατάσταση· τρέξε τον lookup seeder.');
        }

        return $this->section('lookups', 'Πίνακες (lookups)', $items);
    }

    /**
     * Product income classification (§8.6): a product's VAT is NOT NULL by schema,
     * but its §8.6 income category is driven by its product CATEGORY. A category with
     * no bucket → its lines fall back to the invoice-type default (fine, but worth
     * knowing — «ποια κατηγορία εσόδων ανήκει;»).
     *
     * @return array{key:string, label:string, status:string, items: list<array{status:string, message:string}>}
     */
    private function productsSection(Company $company): array
    {
        $total = ProductCategory::query()->where('company_id', $company->getKey())->count();
        if ($total === 0) {
            return $this->section('products', 'Κατηγορίες εσόδων προϊόντων (§8.6)', [$this->item('ok', 'Καμία κατηγορία προϊόντος ακόμη.')]);
        }

        $noIncome = ProductCategory::query()
            ->where('company_id', $company->getKey())
            ->whereNull('mydata_income_class_category')
            ->count();
        $items = [$noIncome > 0
            ? $this->item('warn', $noIncome.'/'.$total.' κατηγορίες χωρίς κατηγορία εσόδων (§8.6) — τα προϊόντα τους παίρνουν την προεπιλογή του τύπου.')
            : $this->item('ok', 'Και οι '.$total.' κατηγορίες προϊόντων έχουν κατηγορία εσόδων (§8.6).'),
        ];

        return $this->section('products', 'Κατηγορίες εσόδων προϊόντων (§8.6)', $items);
    }

    /** @return array{key:string, label:string, status:string, items: list<array{status:string, message:string}>} */
    private function whmcsSection(Company $company): array
    {
        $income = WhmcsIncomeMap::query()->where('company_id', $company->getKey())->count();
        $payment = WhmcsPaymentMap::query()->where('company_id', $company->getKey())->count();

        // Optional (fallbacks to the invoice-type default exist), so ok either way.
        return $this->section('whmcs', 'Αντιστοιχίσεις WHMCS (προαιρετικές)', [
            $income > 0
                ? $this->item('ok', 'Έσοδα §8.6: '.$income.' ομάδες/προϊόντα αντιστοιχισμένα.')
                : $this->item('ok', 'Έσοδα §8.6: καμία — οι γραμμές παίρνουν τον τύπο default.'),
            $payment > 0
                ? $this->item('ok', 'Πληρωμές §8.12: '.$payment.' gateways αντιστοιχισμένα.')
                : $this->item('ok', 'Πληρωμές §8.12: καμία — οι πληρωμές παίρνουν τον τύπο default.'),
        ]);
    }

    /**
     * @param  list<array{status:string, message:string}>  $items
     * @return array{key:string, label:string, status:string, items: list<array{status:string, message:string}>}
     */
    private function section(string $key, string $label, array $items): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'status' => $this->worst(array_map(fn (array $i) => $i['status'], $items)),
            'items' => $items,
        ];
    }

    /** @return array{status:string, message:string} */
    private function item(string $status, string $message): array
    {
        return ['status' => $status, 'message' => $message];
    }

    /** @param  list<string>  $statuses */
    private function worst(array $statuses): string
    {
        $worst = 'ok';
        foreach ($statuses as $s) {
            if ((self::RANK[$s] ?? 0) > self::RANK[$worst]) {
                $worst = $s;
            }
        }

        return $worst;
    }
}
