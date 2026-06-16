<?php

namespace App\Services\MyData;

use App\Enums\MyDataMode;
use App\Models\Company;
use App\Models\InvoiceType;
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
            $findings[] = new ConfigAuditFinding('warn',
                '0% → κατηγορία AADE 7· απαιτείται κατηγορία απαλλαγής (vatExemptionCategory).', '217');
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
