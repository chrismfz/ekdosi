<?php

namespace App\Actions;

use App\Enums\QuoteStatus;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\Quote;
use App\Services\InvoiceNumberer;
use App\Services\RecomputeInvoiceTotals;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * «Μετατροπή σε Παραστατικό» — turn an accepted Προσφορά into a DRAFT invoice.
 *
 * Mirrors the lock→allocate→create→recompute shape of IssueCreditNote /
 * WhmcsInvoiceFiler::createDraft. It produces a NORMAL draft invoice
 * (local_status='draft', no myDATA): the operator then issues it through the
 * usual invoice lifecycle (Οριστικοποίηση → myDATA). It does NOT file at AADE.
 *
 * Bidirectional history: the quote stores `converted_invoice_id`; the invoice
 * exposes the reverse via Invoice::convertedFromQuote(). One write closes both
 * directions, and the `invoices` table needs no new column.
 *
 * Idempotent: refuses if the quote already points at an invoice.
 */
class ConvertQuoteToInvoice
{
    public function __construct(
        private readonly InvoiceNumberer $numberer,
        private readonly RecomputeInvoiceTotals $recompute,
    ) {}

    public function __invoke(Quote $quote, InvoiceType $invoiceType): Invoice
    {
        if ($quote->isConverted()) {
            throw new RuntimeException(
                'Η προσφορά έχει ήδη μετατραπεί σε παραστατικό (#'
                .$quote->converted_invoice_id.').'
            );
        }

        if ($invoiceType->company_id !== $quote->company_id) {
            throw new RuntimeException(
                'Ο τύπος παραστατικού ανήκει σε άλλη εταιρεία.'
            );
        }

        if ($quote->status !== QuoteStatus::Accepted) {
            throw new RuntimeException(
                'Μόνο αποδεκτή προσφορά μετατρέπεται σε παραστατικό.'
            );
        }

        // Leads L1: a quote issued to a lead has no customer yet. An invoice
        // without a customer has no Καρτέλα and no myDATA counterpart — convert
        // the lead first (that back-fills the quote's customer_id).
        if ($quote->lead_id !== null && $quote->customer_id === null) {
            throw new RuntimeException(
                'Η προσφορά ανήκει σε lead που δεν έχει γίνει πελάτης — κάνε πρώτα «Μετατροπή σε πελάτη» στο lead.'
            );
        }

        $quote->loadMissing('lines');

        if ($quote->lines->isEmpty()) {
            throw new RuntimeException(
                'Η προσφορά δεν έχει γραμμές — δεν μπορεί να μετατραπεί σε παραστατικό.'
            );
        }

        return DB::transaction(function () use ($quote, $invoiceType) {
            // Lock + re-check under the row lock: a concurrent double-submit
            // (two clicks) can't create two invoices for one quote. The losing
            // writer blocks here, then sees converted_invoice_id set and bails.
            $locked = Quote::query()->whereKey($quote->id)->lockForUpdate()->first();
            if ($locked === null || $locked->converted_invoice_id !== null) {
                throw new RuntimeException('Η προσφορά έχει ήδη μετατραπεί σε παραστατικό.');
            }

            $allocation = $this->numberer->allocate($quote->company, $invoiceType->code);

            $invoice = Invoice::create([
                'company_id' => $quote->company_id,
                'invoice_type_id' => $invoiceType->id,
                'customer_id' => $quote->customer_id,
                'code' => $allocation->code,
                'invcode' => $allocation->invcode,
                'issued_at' => now(),
                'local_status' => 'draft',
                'header_discount_percent' => $quote->header_discount_percent,
                // Party snapshot — direct copy (same column names).
                'company_name' => $quote->company_name,
                'vat_no' => $quote->vat_no,
                'vies_vat' => $quote->vies_vat,
                'occupation' => $quote->occupation,
                'address1' => $quote->address1,
                'address2' => $quote->address2,
                'city' => $quote->city,
                'postcode' => $quote->postcode,
                'country' => $quote->country,
                'notes' => $quote->customer_notes,
            ]);

            foreach ($quote->lines as $line) {
                $invoice->lines()->create([
                    'company_id' => $quote->company_id,
                    'product_id' => $line->product_id,
                    'product_descr' => $line->product_descr,
                    'qty' => $line->qty,
                    'price_per_item' => $line->price_per_item,
                    'discount' => $line->discount,
                    // InvoiceLine throws on null VAT; default a missing quote
                    // VAT to 0 so the convert never explodes.
                    'vat_percent' => $line->vat_percent ?? 0,
                    'metric_unit' => $line->metric_unit,
                    'notes' => $line->notes,
                ]);
            }

            // Link both directions (invoice read via convertedFromQuote()).
            $quote->forceFill(['converted_invoice_id' => $invoice->id])->save();

            ($this->recompute)($invoice);

            return $invoice->refresh();
        });
    }
}
