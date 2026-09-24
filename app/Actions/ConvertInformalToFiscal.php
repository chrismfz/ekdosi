<?php

namespace App\Actions;

use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\InvoiceType;
use App\Services\RecomputeInvoiceTotals;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * «Μετατροπή σε φορολογικό» — docs/non-billable-services.md §5. Turns an issued
 * INFORMAL document (ΕΣΩ/ΔΟΚ — a friend who ends up paying, a new customer who
 * turns out to want a receipt, the accountant asking for one) into a DRAFT fiscal
 * document of the chosen type, which the operator then issues through the normal
 * lifecycle (Οριστικοποίηση → myDATA / πάροχος). It files nothing itself.
 *
 * Rules (the design doc's §5):
 *   - a NEW draft with TODAY's date — the issue date is the legal one, never
 *     backdated to the informal's;
 *   - NO service contract link: the informal already renewed the service / domain;
 *     carrying it would advance the next charge and re-renew the domain again;
 *   - the informal document STAYS as the trace (still out of every total), linked
 *     via the draft's `converted_from_invoice_id`; once — while a live (not
 *     cancelled, not deleted) conversion exists, a second one is refused;
 *   - one delivery, one stock-out: when the fiscal is issued it TAKES OVER the
 *     informal's stock-out (StockService::transferSaleFromConvertedSource), and
 *     hands it back if cancelled;
 *   - never the other way round: a fiscal document is cancelled or credited.
 *
 * An informal DRAFT isn't converted: it has no number yet — just change its series.
 */
class ConvertInformalToFiscal
{
    public function __construct(
        private readonly RecomputeInvoiceTotals $recompute,
    ) {}

    public function __invoke(Invoice $informal, InvoiceType $type): Invoice
    {
        if (! $informal->isInformal()) {
            throw new RuntimeException('Μόνο ένα άτυπο παραστατικό μετατρέπεται σε φορολογικό.');
        }
        if ($informal->local_status !== 'active') {
            throw new RuntimeException($informal->local_status === 'draft'
                ? 'Το άτυπο είναι ακόμα πρόχειρο — άλλαξε απλώς το είδος του, δεν χρειάζεται μετατροπή.'
                : 'Ένα ακυρωμένο άτυπο δεν μετατρέπεται σε φορολογικό.');
        }
        if ((int) $type->company_id !== (int) $informal->company_id) {
            throw new RuntimeException('Το είδος παραστατικού ανήκει σε άλλη εταιρεία.');
        }
        if ($type->is_informal || $type->is_credit || ! InvoiceType::query()->monetary()->whereKey($type->id)->exists()) {
            throw new RuntimeException('Διάλεξε φορολογικό είδος πώλησης (όχι άτυπο, πιστωτικό ή δελτίο αποστολής).');
        }

        // A δελτίο linked to the informal moved its goods (whichever-first): the
        // stock hand-over can't follow it. Rare — unlink it first.
        if ($informal->deliveryNotes()->exists()) {
            throw new RuntimeException('Το άτυπο έχει συνδεδεμένο δελτίο αποστολής — αποσύνδεσέ το πρώτα.');
        }

        $informal->loadMissing('lines');
        if ($informal->lines->isEmpty()) {
            throw new RuntimeException('Το άτυπο δεν έχει γραμμές — δεν μετατρέπεται.');
        }

        return DB::transaction(function () use ($informal, $type) {
            // Lock + re-check under the row lock: two clicks can't make two fiscal
            // documents out of one informal. The loser blocks here, then sees the
            // first one's conversion and bails.
            $locked = Invoice::query()->whereKey($informal->id)->lockForUpdate()->first();
            if ($locked === null || $locked->local_status !== 'active') {
                throw new RuntimeException('Το άτυπο δεν είναι πια ενεργό.');
            }
            if (($existing = $locked->liveConversion()) !== null) {
                throw new RuntimeException('Το άτυπο έχει ήδη μετατραπεί σε φορολογικό ('.$existing->invcode.').');
            }

            // Gapless-at-send: a draft with a provisional identity (no ΑΑ); the real
            // number is allocated at issue, like any other draft.
            $fiscal = Invoice::create([
                'company_id' => $informal->company_id,
                'invoice_type_id' => $type->id,
                'customer_id' => $informal->customer_id,
                'converted_from_invoice_id' => $informal->id,
                'payment_method_id' => $informal->payment_method_id ?? $type->payment_method_id,
                'bank_account_id' => $informal->bank_account_id,
                'distribution_aim_id' => $informal->distribution_aim_id,
                'delivery_method_id' => $informal->delivery_method_id,
                'delivery_date' => $informal->delivery_date,
                'issued_at' => now(),
                'local_status' => 'draft',
                'header_discount_percent' => $informal->header_discount_percent,
                // Taxes are recompute-owned: carry the RATES + categories; the amounts
                // rebuild from the draft's own net (as ReissueInvoiceAsDraft).
                'withhold_rate' => $informal->withhold_rate,
                'withhold_category' => $informal->withhold_category,
                'fees_rate' => $informal->fees_rate,
                'fees_category' => $informal->fees_category,
                'other_taxes_rate' => $informal->other_taxes_rate,
                'other_taxes_category' => $informal->other_taxes_category,
                'stamp_duty_rate' => $informal->stamp_duty_rate,
                'stamp_duty_category' => $informal->stamp_duty_category,
                'deductions_rate' => $informal->deductions_rate,
                'deductions_category' => $informal->deductions_category,
                'notes' => $informal->notes,
                // Party snapshot — the operator can still change the customer / ΑΦΜ on
                // the draft (e.g. a new customer who has now given an ΑΦΜ).
                'company_name' => $informal->company_name,
                'vat_no' => $informal->vat_no,
                'vies_vat' => $informal->vies_vat,
                'occupation' => $informal->occupation,
                'address1' => $informal->address1,
                'address2' => $informal->address2,
                'city' => $informal->city,
                'postcode' => $informal->postcode,
                'country' => $informal->country,
                'language' => $informal->language,
                'counterpart_branch' => $informal->counterpart_branch,
            ]);

            foreach ($informal->lines as $line) {
                InvoiceLine::create([
                    'company_id' => $informal->company_id,
                    'invoice_id' => $fiscal->id,
                ] + $line->copyAttributes());
            }

            ($this->recompute)($fiscal->fresh('lines'));

            return $fiscal->refresh();
        });
    }
}
