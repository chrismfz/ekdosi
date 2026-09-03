<?php

namespace App\Actions;

use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Services\InvoiceNumberer;
use App\Services\RecomputeInvoiceTotals;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Reissue an invoice as a fresh DRAFT copy — new ΑΑ of the SAME invoice type,
 * same customer/lines/party snapshot, ready to fix and re-issue through the
 * normal lifecycle. The myDATA/credit/whmcs links are intentionally dropped and
 * the money caches are recomputed (never copied).
 *
 * Used standalone for «Επανέκδοση» (re-bill after a credit-cancelled original),
 * and as the second half of StornoAndReissue (credit + reissue in one click).
 * This action does NOT issue a credit note and does NOT submit anything.
 */
class ReissueInvoiceAsDraft
{
    public function __construct(
        private InvoiceNumberer $numberer,
        private RecomputeInvoiceTotals $recompute,
    ) {}

    public function __invoke(Invoice $original): Invoice
    {
        if ($original->credited_invoice_id !== null) {
            throw new RuntimeException('Δεν γίνεται επανέκδοση πιστωτικού τιμολογίου.');
        }

        $original->loadMissing(['lines', 'company', 'invoiceType']);

        if ($original->invoiceType === null) {
            throw new RuntimeException('Το αρχικό παραστατικό δεν έχει τύπο — αδύνατη η επανέκδοση.');
        }

        return DB::transaction(function () use ($original) {
            $allocation = $this->numberer->allocate($original->company, $original->invoiceType->code);

            $reissue = Invoice::create([
                'company_id' => $original->company_id,
                'invoice_type_id' => $original->invoice_type_id,
                'customer_id' => $original->customer_id,
                'payment_method_id' => $original->payment_method_id,
                'bank_account_id' => $original->bank_account_id,
                'distribution_aim_id' => $original->distribution_aim_id,
                'delivery_method_id' => $original->delivery_method_id,
                'delivery_date' => $original->delivery_date,
                'issued_at' => now(),
                'code' => $allocation->code,
                'invcode' => $allocation->invcode,
                'local_status' => 'draft',
                // PROV-019: remember what this replaces, so filing it can soft-warn
                // while the reversed original is still standing at AADE.
                'reissued_from_invoice_id' => $original->id,
                'header_discount_percent' => $original->header_discount_percent,
                // Taxes are recompute-owned (RecomputeInvoiceTaxes): carry the RATES +
                // categories; the amounts rebuild from the reissue's own net on save.
                // (Product-linked per-unit fees carry via the copied lines → products.)
                'withhold_rate' => $original->withhold_rate,
                'withhold_category' => $original->withhold_category,
                'fees_rate' => $original->fees_rate,
                'fees_category' => $original->fees_category,
                'other_taxes_rate' => $original->other_taxes_rate,
                'other_taxes_category' => $original->other_taxes_category,
                'stamp_duty_rate' => $original->stamp_duty_rate,
                'stamp_duty_category' => $original->stamp_duty_category,
                'deductions_rate' => $original->deductions_rate,
                'deductions_category' => $original->deductions_category,
                'notes' => $original->notes,
                // Party snapshot — same counterparty as the original.
                'company_name' => $original->company_name,
                'vat_no' => $original->vat_no,
                'vies_vat' => $original->vies_vat,
                'occupation' => $original->occupation,
                'address1' => $original->address1,
                'address2' => $original->address2,
                'city' => $original->city,
                'postcode' => $original->postcode,
                'country' => $original->country,
            ]);

            foreach ($original->lines as $line) {
                InvoiceLine::create([
                    'company_id' => $original->company_id,
                    'invoice_id' => $reissue->id,
                    'product_id' => $line->product_id,
                    'qty' => $line->qty,
                    'price_per_item' => $line->price_per_item,
                    'discount' => $line->discount,
                    'vat_percent' => $line->vat_percent,
                    'product_descr' => $line->product_descr,
                    'metric_unit' => $line->metric_unit,
                    'notes' => $line->notes,
                ]);
            }

            ($this->recompute)($reissue->fresh('lines'));

            return $reissue->refresh();
        });
    }
}
