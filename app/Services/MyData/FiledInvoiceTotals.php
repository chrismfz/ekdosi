<?php

namespace App\Services\MyData;

use App\Models\Invoice;
use App\Services\InvoiceVatBreakdown;

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

        $breakdown = InvoiceVatBreakdown::for($invoice);
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
