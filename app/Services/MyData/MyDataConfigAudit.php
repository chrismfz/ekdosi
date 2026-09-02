<?php

namespace App\Services\MyData;

use App\Enums\MyDataMode;
use App\Models\Company;
use App\Models\InvoiceType;
use App\Models\PaymentMethod;
use App\Models\VatCategory;
use App\Support\MyData\Codes;

/**
 * Read-only configuration audit for myDATA filing — the single source of truth
 * shared by THREE surfaces:
 *   - the `mydata:preflight` CLI command (thin renderer over this),
 *   - the «Έλεγχος ρυθμίσεων» console tab (structured insight + deep-links),
 *   - the Invoice Types list myDATA-readiness badge (per-row status).
 *
 * It checks a tenant's CONFIGURATION against the AADE code tables
 * (App\Support\MyData\Codes, §8 appendix) so gaps are caught BEFORE AADE rejects
 * a real submission. No AADE calls. Each finding carries the AADE error it would
 * otherwise cause, and each row carries the model id so a UI can link straight to
 * the fix.
 */
class MyDataConfigAudit
{
    /** Full audit for a tenant: readiness + every invoice type + every VAT category. */
    public function audit(Company $company): ConfigAuditResult
    {
        $invoiceTypes = InvoiceType::query()
            ->where('company_id', $company->getKey())
            ->orderBy('code')
            ->get()
            ->map(fn (InvoiceType $t) => $this->auditInvoiceType($t))
            ->all();

        $vatCategories = VatCategory::query()
            ->where('company_id', $company->getKey())
            ->orderBy('rate')
            ->get()
            ->map(fn (VatCategory $v) => $this->auditVatCategory($v))
            ->all();

        return new ConfigAuditResult(
            company: $company,
            // Fold the "nothing configured" warnings into the readiness row so they
            // surface on every consumer (CLI / tab / badge), like the old preflight.
            tenant: $this->auditTenant($company, empty($invoiceTypes), empty($vatCategories)),
            invoiceTypes: $invoiceTypes,
            vatCategories: $vatCategories,
        );
    }

    /** Tenant-level readiness (provider / mode / credentials / empty config). One row. */
    public function auditTenant(Company $company, bool $noInvoiceTypes = false, bool $noVatCategories = false): ConfigAuditRow
    {
        $findings = [];

        if ($company->einvoice_provider !== 'gr-mydata') {
            $findings[] = new ConfigAuditFinding('warn',
                "Ο πάροχος είναι «{$company->einvoice_provider}», όχι gr-mydata — δεν θα γίνει υποβολή στο myDATA.");
        }
        if ($company->mydata_mode_enum === MyDataMode::Off) {
            $findings[] = new ConfigAuditFinding('warn',
                'Η λειτουργία myDATA είναι κλειστή (Off) — δεν θα επιχειρηθεί καμία υποβολή.');
        }
        [$aadeId, $subKey] = $company->mydataCredentials();
        if (empty($aadeId) || empty($subKey)) {
            $findings[] = new ConfigAuditFinding('warn',
                "Δεν έχουν οριστεί διαπιστευτήρια myDATA για την ενεργή λειτουργία («{$company->mydata_mode_enum->value}»).");
        }
        if ($noInvoiceTypes) {
            $findings[] = new ConfigAuditFinding('warn', 'Δεν έχουν οριστεί τύποι παραστατικών.');
        }
        if ($noVatCategories) {
            $findings[] = new ConfigAuditFinding('warn', 'Δεν έχουν οριστεί κατηγορίες ΦΠΑ.');
        }

        // MYD-4 (AUDIT): a payment method WITHOUT a myDATA §8.12 type is filed as
        // «Μετρητά» (type 3) — so card/bank-transfer invoices silently misreport
        // the payment means. Surface it here (visible in preflight / «Έλεγχος
        // ρυθμίσεων» / go-live) so it's mapped BEFORE it matters. Only for tenants
        // that actually file to AADE (direct or via provider).
        if (in_array($company->einvoice_provider, ['gr-mydata', 'gr-provider'], true)) {
            $unmapped = PaymentMethod::query()
                ->where('company_id', $company->getKey())
                ->whereNull('mydata_payment_type')
                ->orderBy('id')
                ->pluck('description');
            if ($unmapped->isNotEmpty()) {
                $sample = $unmapped->take(3)->implode('», «');
                $more = $unmapped->count() > 3 ? ' (+'.($unmapped->count() - 3).')' : '';
                $findings[] = new ConfigAuditFinding('warn',
                    'Τρόποι πληρωμής χωρίς αντιστοίχιση myDATA («'.$sample.'»'.$more.') — θα δηλωθούν ως '
                    .'«Μετρητά» (τύπος 3). Όρισε τον τύπο §8.12 στους «Τρόποι πληρωμής».');
            }
        }

        return new ConfigAuditRow('tenant', $company->name, $findings);
    }

    /**
     * Audit a single invoice type. Reusable on its own (the Invoice Types badge
     * calls this per row) — pure in-memory check against Codes, no extra query.
     */
    public function auditInvoiceType(InvoiceType $type): ConfigAuditRow
    {
        $findings = [];
        $mt = $type->mydata_type;

        if (empty($mt)) {
            // Blank = "never filed to myDATA" — legitimate for delivery /
            // aggregation / internal docs (ΣΔΕΠ, ΣΔΑΠ…). Only a problem if the
            // operator expects it filed, so it's a WARN, not an ERROR.
            $findings[] = new ConfigAuditFinding('warn',
                'Χωρίς myDATA τύπο — δεν υποβάλλεται (ΟΚ για δελτία/εσωτερικά· πρόβλημα αν περιμένετε υποβολή).');
        } elseif (! Codes::invoiceTypeExists($mt)) {
            $findings[] = new ConfigAuditFinding('error',
                "Ο τύπος myDATA «{$mt}» δεν είναι έγκυρος τύπος AADE.", '223');
        } elseif (Codes::isIncomeInvoiceType($mt)) {
            $cls = $type->mydata_income_class;
            $cat = $type->mydata_income_class_category;

            if (empty($cls)) {
                $findings[] = new ConfigAuditFinding('error', 'Λείπει ο τύπος χαρακτηρισμού εσόδων.', '230');
            } elseif (! Codes::isValidIncomeClassType($cls)) {
                $findings[] = new ConfigAuditFinding('error', "Ο χαρακτηρισμός «{$cls}» δεν είναι έγκυρος κωδικός E3_* (§8.9).");
            }

            if (empty($cat)) {
                $findings[] = new ConfigAuditFinding('error', 'Λείπει η κατηγορία χαρακτηρισμού εσόδων.', '230');
            } elseif (! Codes::isValidIncomeClassCategory($cat)) {
                $findings[] = new ConfigAuditFinding('error', "Η κατηγορία «{$cat}» δεν είναι έγκυρος κωδικός (§8.8).");
            }
        }

        // MYD-9: the per-line quantity flag must match the type's goods/services
        // nature, or the FIRST filing of a hand-made type is rejected ([204] goods
        // need quantity / [205] services forbid it). WARN — the seeder sets it
        // right; this catches a manually-created type.
        if (! empty($mt) && Codes::invoiceTypeExists($mt)) {
            $goods = Codes::typeIsGoods($mt);
            $requiresQty = (bool) $type->mydata_requires_quantity;
            if ($goods === true && ! $requiresQty) {
                $findings[] = new ConfigAuditFinding('warn',
                    'Τύπος αγαθών χωρίς «απαιτεί ποσότητα» — η ΑΑΔΕ ζητά ποσότητα ανά γραμμή για αγαθά ([204]).');
            } elseif ($goods === false && $requiresQty) {
                $findings[] = new ConfigAuditFinding('warn',
                    'Τύπος υπηρεσιών με «απαιτεί ποσότητα» — η ΑΑΔΕ απορρίπτει per-line ποσότητα σε υπηρεσίες ([205]).');
            }
        }

        return new ConfigAuditRow(
            kind: 'invoice_type',
            label: "[{$type->code}] {$type->name}",
            findings: $findings,
            modelId: (int) $type->getKey(),
            detail: $mt ?: '—',
        );
    }

    /** Audit a single VAT category's rate→AADE-category mapping. */
    public function auditVatCategory(VatCategory $vat): ConfigAuditRow
    {
        $findings = [];
        $rate = (float) $vat->rate;
        $matches = Codes::vatCategoriesForRate($rate);

        if ($rate === 0.0) {
            // MYD-007 / MYD-004: a 0% (AADE category 7) row filed WITHOUT a valid
            // §8.3 exemption reason is rejected by AADE ([217]) — so a reason-less
            // 0% category is a BLOCKING error, not a warning (the old warning let
            // preflight exit green — the exact false-green MYD-004 flagged). The
            // helper tells the operator which reason: υπηρεσία ΕΕ→4, αγαθά ΕΕ→14,
            // εξαγωγή→8 (App\Support\MyData\VatExemptionGuidance).
            $reason = $vat->vat_exemption_category;
            if ($reason === null || $reason === '' || ! Codes::vatExemptionExists((int) $reason)) {
                $findings[] = new ConfigAuditFinding('error',
                    '0% χωρίς έγκυρη αιτία απαλλαγής §8.3 — η ΑΑΔΕ απορρίπτει [217]. '
                    .'Όρισε αιτία: ενδοκοιν. υπηρεσία→4 (άρθρο 18), ενδοκοιν. αγαθά→14 (άρθρο 33), '
                    .'εξαγωγή→8 (άρθρο 29), εγχώριο reverse-charge→16 (άρθρο 45).', '217');
            }
        } elseif (empty($matches)) {
            $findings[] = new ConfigAuditFinding('error',
                "Ο συντελεστής {$rate}% δεν αντιστοιχεί σε καμία κατηγορία ΦΠΑ AADE (§8.2).");
        } elseif (count($matches) > 1) {
            $list = implode('/', $matches);
            $findings[] = new ConfigAuditFinding('warn',
                "Ο συντελεστής {$rate}% είναι διφορούμενος — κατηγορίες AADE {$list}· επιβεβαιώστε το καθεστώς.");
        }

        return new ConfigAuditRow(
            kind: 'vat_category',
            label: $this->rateLabel($rate).' — '.($vat->description ?? ''),
            findings: $findings,
            modelId: (int) $vat->getKey(),
            detail: $this->rateLabel($rate),
        );
    }

    private function rateLabel(float $rate): string
    {
        return rtrim(rtrim(number_format($rate, 2), '0'), '.').'%';
    }
}
