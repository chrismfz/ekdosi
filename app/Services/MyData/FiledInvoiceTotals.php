<?php

namespace App\Services\MyData;

use App\Models\Invoice;
use App\Services\InvoiceVatBreakdown;
use Throwable;

/**
 * The net/gross an invoice was (or would be) FILED with — the only figures that
 * are comparable against what AADE returns for its MARK.
 *
 * Why not the ledger columns: `invoices.net_total`/`gross_total` are a DIFFERENT
 * roll-up from the submitted summary. RecomputeInvoiceTotals rounds ONCE over the
 * whole sum (`round(Σ line net × discountFactor, 2)`) and its docblock warns these
 * are "two different roll-ups with two different rounding shapes; don't conflate
 * them", while AadeInvoiceDocument files `InvoiceVatBreakdown::totalNet()`, which
 * rounds PER VAT RATE. On a multi-rate invoice with a header discount the two
 * differ by a cent or two — enough to report a byte-correct document as a content
 * conflict. And AADE's `totalGrossValue` additionally carries the [208]
 * adjustment (fees + stamp + otherTaxes − deductions − withholding), which
 * `gross_total` deliberately never does.
 *
 * A null field means "we cannot faithfully reconstruct what was filed" — the
 * caller must treat it as UNVERIFIED, never as a value to contradict AADE with.
 * The reconstruction FAILS CLOSED (both fields null): no lines, a line missing an
 * amount/rate (legacy `net_price`/`gross_price`/`vat_percent` are nullable and the
 * breakdown would cast them to 0.0), or a header discount InvoiceVatBreakdown
 * refuses (<0 or >=100 — the legacy UI allowed 100%). None of these may abort the
 * whole reconciliation or pass a fabricated 0,00 as a real total.
 */
final readonly class FiledInvoiceTotals
{
    public function __construct(
        public ?float $net,
        public ?float $gross,
    ) {}

    public static function for(Invoice $invoice): self
    {
        $invoice->loadMissing('lines');

        // Nothing to reconstruct from (a header-only/partial row). Reporting 0,00
        // here would contradict AADE with a number we never filed.
        if ($invoice->lines->isEmpty()) {
            return new self(null, null);
        }

        // A line missing an amount or its VAT rate can't be rolled up faithfully:
        // InvoiceVatBreakdown casts the null to 0.0 and yields a plausible-looking
        // total (a partial legacy line), or groups under a bogus '' rate key. Treat
        // the whole document as unverifiable rather than compare a fabricated sum.
        foreach ($invoice->lines as $line) {
            if ($line->net_price === null || $line->gross_price === null || $line->vat_percent === null) {
                return new self(null, null);
            }
        }

        // header_discount_percent outside 0..<100 makes InvoiceVatBreakdown throw
        // (a 100% discount was legal in the legacy UI). Catch it here so one
        // historical row is reported unverified instead of aborting the reconcile.
        try {
            $breakdown = InvoiceVatBreakdown::for($invoice);
        } catch (Throwable) {
            return new self(null, null);
        }

        $net = round($breakdown->totalNet(), 2);

        // Withholding recorded WITHOUT its §8.4 category — every Firebird-imported
        // invoice, since the ETL copies WITHHOLD_AMOUNT but no category. Whether
        // AADE's gross was reduced depends on that category (cats 8/9/10 are
        // informational and do NOT reduce it), so the filed gross is genuinely
        // unknowable here. Report it as unverified rather than guessing and
        // flagging every legacy ΠΚ-3 invoice as a content conflict.
        if ((float) ($invoice->withhold_amount ?? 0) > 0 && $invoice->withhold_category === null) {
            return new self($net, null);
        }

        return new self($net, round($breakdown->totalGross() + $invoice->additionalTaxAdjustment(), 2));
    }
}
