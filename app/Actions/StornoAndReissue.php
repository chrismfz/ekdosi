<?php

namespace App\Actions;

use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\InvoiceType;
use App\Services\InvoiceNumberer;
use App\Services\RecomputeInvoiceTotals;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * "Storno & reissue" — the one-step correction for an invoice that already
 * received a MARK through a provider (ΥΠΑΗΕΣ), where a plain cancel is NOT
 * available (only a credit note reverses it). Two things in one transaction:
 *
 *   1. a FULL credit note (every line, full remaining qty) via IssueCreditNote
 *      — the legal reversal that nets the original to zero at AADE;
 *   2. a fresh DRAFT copy of the original (new ΑΑ, same type/customer/lines/
 *      party snapshot) for the operator to fix and re-issue normally.
 *
 * This action only PERSISTS both documents (the reissue as a draft). It does
 * NOT submit anything to myDATA — the caller decides whether to file the
 * credit note now (mirroring IssueCreditNote's opt-in), and the corrected
 * reissue is filed later through the normal lifecycle once reviewed.
 */
class StornoAndReissue
{
    public function __construct(
        private IssueCreditNote $issueCreditNote,
        private InvoiceNumberer $numberer,
        private RecomputeInvoiceTotals $recompute,
    ) {}

    /**
     * @return array{credit: Invoice, reissue: Invoice}
     */
    public function __invoke(Invoice $original, InvoiceType $creditType): array
    {
        if ($original->credited_invoice_id !== null) {
            throw new RuntimeException('Δεν γίνεται storno σε πιστωτικό τιμολόγιο.');
        }

        $original->loadMissing(['lines', 'company', 'invoiceType']);

        if ($original->invoiceType === null) {
            throw new RuntimeException('Το αρχικό παραστατικό δεν έχει τύπο — αδύνατη η επανέκδοση.');
        }

        return DB::transaction(function () use ($original, $creditType) {
            // 1) Full credit note for every line (full remaining qty).
            //    IssueCreditNote validates the credit type + same tenant and
            //    locks the original; nesting in this transaction is a savepoint.
            $selections = $original->lines
                ->map(fn (InvoiceLine $l) => ['line_id' => $l->id, 'qty' => (float) $l->qty])
                ->all();

            $credit = ($this->issueCreditNote)($original, $creditType, $selections);

            // 2) Fresh DRAFT copy of the original for correction. New ΑΑ of the
            //    SAME invoice type; party snapshot + header carried over so the
            //    operator only fixes the actual mistake. Caches are recomputed,
            //    never copied; myDATA/credit/whmcs links are intentionally dropped.
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
                'header_discount_percent' => $original->header_discount_percent,
                // Withholding is operator-entered (NOT recomputed) — copy BOTH
                // the amount and its category, else the reissue comes back with
                // a category but amount 0 (the invalid combo the form guards).
                'withhold_category' => $original->withhold_category,
                'withhold_amount' => $original->withhold_amount,
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

            return ['credit' => $credit->refresh(), 'reissue' => $reissue->refresh()];
        });
    }
}
