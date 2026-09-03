<?php

namespace App\Actions;

use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\InvoiceType;
use App\Models\ReturnInvoiceExtra;
use App\Services\InvoiceBalance;
use App\Services\InvoiceNumberer;
use App\Services\RecomputeInvoiceTotals;
use App\Services\RecomputeReturnedQuantities;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Issue a credit note against an existing invoice (full or partial
 * return). Mirrors the CreateInvoice issue pattern: allocate ΑΑ under a
 * row lock + persist inside one transaction; the AADE submission runs
 * AFTER commit (the caller does DB::afterCommit → MyDataSubmitter), so
 * no HTTP round-trip happens while holding row locks and there's no
 * orphan-MARK window.
 *
 * The credit note is a normal Invoice of a credit InvoiceType with
 * POSITIVE lines and `credited_invoice_id` pointing at the original.
 * The reduction is expressed via the original's credited_total
 * (InvoiceBalance), NOT negative gross — so InvoiceVatBreakdown / the
 * myDATA payload keep positive magnitudes. Returned quantities are
 * tracked per ORIGINAL line in return_invoice_extras (legacy parity).
 *
 * This action only PERSISTS the draft credit note; it does not submit
 * to myDATA. The caller submits via EInvoiceSubmitterFactory.
 *
 * Two entry points share ONE locked transaction body (`issue`):
 *   - __invoke()        — credit the exact per-line quantities the operator
 *                         chose (partial or full), each validated against the
 *                         line's live remaining qty;
 *   - reverseRemaining() — credit every line's REMAINING qty in one shot, for
 *                         the full-reversal actions (PROV-018).
 */
class IssueCreditNote
{
    /**
     * Credit the exact per-line quantities the operator selected. Each is
     * validated against the line's live remaining quantity under the lock.
     *
     * @param  array<int, array{line_id: int, qty: float}>  $selections
     */
    public function __invoke(Invoice $original, InvoiceType $creditType, array $selections): Invoice
    {
        return $this->issue($original, $creditType, fn (Collection $lines) => $selections);
    }

    /**
     * Reverse every line's REMAINING quantity — the full qty minus what prior
     * credit notes already returned — computed under the original-row lock so
     * two concurrent reversals can't both claim the same remainder. Zero-
     * remainder lines are skipped; an already-fully-credited invoice throws a
     * clear error instead of the misleading per-line over-credit message.
     *
     * This is the one path behind «Ακύρωση μέσω πιστωτικού» and «Ακύρωση &
     * επανέκδοση» (PROV-018): they used to request the full ORIGINAL qty and so
     * failed the moment a line had been partially credited. On a provider
     * channel — where a credit note is the ONLY way to reverse a MARKed
     * invoice (no CancelInvoice) — that left the operator unable to cancel the
     * remaining quantity at all.
     */
    public function reverseRemaining(Invoice $original, InvoiceType $creditType): Invoice
    {
        return $this->issue($original, $creditType, function (Collection $lines): array {
            $selections = $lines
                ->map(fn (InvoiceLine $line) => [
                    'line_id' => $line->id,
                    'qty' => $this->remainingQty($line),
                ])
                ->filter(fn (array $sel) => $sel['qty'] > 0.0001)
                ->values()
                ->all();

            if ($selections === []) {
                throw new RuntimeException(
                    'Το παραστατικό έχει ήδη πιστωθεί πλήρως — δεν υπάρχει υπόλοιπο προς αντιστροφή.'
                );
            }

            return $selections;
        });
    }

    /**
     * Shared locked body. `$resolveSelections` runs AFTER the original-row lock
     * so remaining-qty math (reverseRemaining) sees the latest qty_returned.
     *
     * @param  Closure(Collection<int, InvoiceLine>): array<int, array{line_id: int, qty: float}>  $resolveSelections
     */
    private function issue(Invoice $original, InvoiceType $creditType, Closure $resolveSelections): Invoice
    {
        if ($original->credited_invoice_id !== null) {
            throw new RuntimeException('Cannot issue a credit note against another credit note.');
        }
        if (! $creditType->is_credit) {
            throw new RuntimeException("Invoice type {$creditType->code} is not a credit type (is_credit = false).");
        }
        if ((int) $creditType->company_id !== (int) $original->company_id) {
            throw new RuntimeException('Credit invoice type belongs to a different tenant.');
        }

        $original->loadMissing(['lines', 'company']);
        $linesById = $original->lines->keyBy('id');

        return DB::transaction(function () use ($original, $creditType, $resolveSelections, $linesById) {
            // Lock the original so two concurrent credit notes can't both
            // read the same already-returned qty and over-credit a line
            // (TOCTOU on the remaining-qty check below). The selection
            // resolver runs AFTER the lock so «reverse remaining» computes
            // each line's remainder from the latest qty_returned.
            Invoice::query()->whereKey($original->id)->lockForUpdate()->first();

            $selections = $resolveSelections($original->lines);

            $allocation = app(InvoiceNumberer::class)->allocate($original->company, $creditType->code);

            $credit = Invoice::create([
                'company_id' => $original->company_id,
                'invoice_type_id' => $creditType->id,
                'customer_id' => $original->customer_id,
                'payment_method_id' => $original->payment_method_id,
                'credited_invoice_id' => $original->id,
                'issued_at' => now(),
                'code' => $allocation->code,
                'invcode' => $allocation->invcode,
                'header_discount_percent' => $original->header_discount_percent,
                // Mirror the original's additional-tax RATES/categories so the
                // credit note reverses the withholding/fees too: RecomputeInvoiceTaxes
                // then recomputes its amounts from the credit note's (possibly partial)
                // net, so its payable_total matches what it reverses (no phantom
                // negative owed on a withholding invoice). Product-linked fees come
                // back via the copied product_id on the lines below.
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
                // Party snapshot copied from the original — the credit
                // note is a legal document for the same counterparty.
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

            $any = false;
            foreach ($selections as $sel) {
                $line = $linesById->get($sel['line_id']);
                if (! $line) {
                    throw new RuntimeException(
                        "Line {$sel['line_id']} does not belong to invoice {$original->invcode}."
                    );
                }
                $qty = (float) $sel['qty'];
                if ($qty <= 0) {
                    continue;
                }

                $alreadyReturned = (float) (ReturnInvoiceExtra::query()
                    ->where('invoice_line_id', $line->id)->value('qty_returned') ?? 0);
                $remaining = (float) $line->qty - $alreadyReturned;
                if ($qty > $remaining + 0.0001) {
                    throw new RuntimeException(sprintf(
                        'Επιστροφή %.3f > διαθέσιμη ποσότητα %.3f για τη γραμμή «%s».',
                        $qty, $remaining, $line->product_descr ?? ('#'.$line->id),
                    ));
                }

                // Positive credit line (the saving hook computes net/gross).
                // original_line_id links it to the line it credits (MON-1) so
                // qty_returned can be recomputed from live credit notes and a
                // cancelled credit note frees the quantity again.
                InvoiceLine::create([
                    'company_id' => $original->company_id,
                    'invoice_id' => $credit->id,
                    'original_line_id' => $line->id,
                    'product_id' => $line->product_id,
                    'qty' => $qty,
                    'price_per_item' => $line->price_per_item,
                    'discount' => $line->discount,
                    'vat_percent' => $line->vat_percent,
                    // MYD-007: a credit note inherits the original line's §8.3 reason,
                    // so a credit of a 0% document files the SAME exemption reason.
                    'vat_exemption_category' => $line->vat_exemption_category,
                    // MYD-006: likewise carry the original line's §8.6 income-class
                    // snapshot (WHMCS-bridge stamp), so the credit reverses under the
                    // SAME income category — not the credit type's default.
                    'mydata_income_class' => $line->mydata_income_class,
                    'mydata_income_class_category' => $line->mydata_income_class_category,
                    'product_descr' => $line->product_descr,
                    'metric_unit' => $line->metric_unit,
                    'notes' => $line->notes,
                ]);

                // Track returned qty on the ORIGINAL line (legacy parity).
                $extra = ReturnInvoiceExtra::query()->firstOrNew(['invoice_line_id' => $line->id]);
                $extra->company_id = $original->company_id;
                $extra->qty_returned = $alreadyReturned + $qty;
                $extra->save();

                $any = true;
            }

            if (! $any) {
                throw new RuntimeException('Επιλέξτε τουλάχιστον μία γραμμή με ποσότητα προς πίστωση.');
            }

            app(RecomputeInvoiceTotals::class)($credit);
            app(InvoiceBalance::class)->recompute($original);
            // Canonicalise qty_returned from live credit notes (MON-1). The
            // mid-loop writes above kept the intra-transaction remaining-qty
            // check correct; this normalises to Σ(live) so it stays reversible.
            app(RecomputeReturnedQuantities::class)($original);

            return $credit->refresh();
        });
    }

    /**
     * A single original line's remaining returnable quantity: its qty minus
     * what live credit notes have already returned (return_invoice_extras).
     * Read under the caller's lock so concurrent reversals stay consistent.
     */
    private function remainingQty(InvoiceLine $line): float
    {
        $returned = (float) (ReturnInvoiceExtra::query()
            ->where('invoice_line_id', $line->id)->value('qty_returned') ?? 0);

        return (float) $line->qty - $returned;
    }
}
