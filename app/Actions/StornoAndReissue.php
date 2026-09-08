<?php

namespace App\Actions;

use App\Models\Invoice;
use App\Models\InvoiceType;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * "Storno & reissue" — the one-step correction for an invoice that already
 * received a MARK through a provider (ΥΠΑΗΕΣ), where a plain cancel is NOT
 * available (only a credit note reverses it). Two things in one transaction:
 *
 *   1. a credit note for every line's REMAINING qty via IssueCreditNote::
 *      reverseRemaining (PROV-018) — the legal reversal that nets the original
 *      to zero at AADE, and that still works after an earlier partial credit;
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
        private ReissueInvoiceAsDraft $reissueAsDraft,
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
            // 1) Full credit note for every line's REMAINING qty (PROV-018).
            //    reverseRemaining computes each line's remainder under the
            //    original-row lock, so a storno still works after an earlier
            //    partial credit (it reverses only what is left, not the full
            //    original qty). IssueCreditNote validates the credit type +
            //    same tenant and locks the original; nesting in this
            //    transaction is a savepoint.
            $credit = $this->issueCreditNote->reverseRemaining($original, $creditType);

            // 2) Fresh DRAFT copy of the original for correction — delegated to
            //    ReissueInvoiceAsDraft (its own savepoint within this transaction).
            $reissue = ($this->reissueAsDraft)($original);

            return ['credit' => $credit->refresh(), 'reissue' => $reissue];
        });
    }
}
