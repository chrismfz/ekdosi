<?php

namespace App\Services\Accounting;

use Carbon\CarbonInterface;

/**
 * One line of the Βιβλίο Εσόδων-Εξόδων (απλογραφικά / Β' κατηγορίας): a single
 * document — an invoice (έσοδο) or an expense (έξοδο) — projected into a
 * neutral, accountant-friendly shape. This is a READ-MODEL row derived from the
 * existing invoices/expenses; the book never persists anything of its own.
 *
 * Amounts are SIGNED: a credit note (πιστωτικό) carries NEGATIVE net/vat/gross
 * so a column sum is the real period figure (revenue net of credits, deductible
 * input VAT net of supplier credits). `isCredit` lets the view label it.
 *
 * The accounting "category" is the myDATA classification we already store —
 * income `category1_x` (from the invoice type) or expense `category2_x` (from
 * the expense classification) — resolved to a Greek label. No GL account number
 * yet (that mapping is a deferred, optional Phase 2).
 */
class LedgerRow
{
    public function __construct(
        public readonly string $book,            // 'income' | 'expense'
        public readonly CarbonInterface $date,
        public readonly string $docType,         // invoice-type code (έσοδα) / myDATA type e.g. 1.1 (έξοδα)
        public readonly string $doc,             // human document id (ΤΠΥ423 / series+aa / MARK)
        public readonly ?string $counterparty,   // customer or supplier name
        public readonly ?string $afm,
        public readonly ?string $categoryCode,   // category1_x | category2_x | null
        public readonly ?string $categoryLabel,  // Greek label, null if unmapped
        public readonly float $net,              // signed
        public readonly float $vat,              // signed
        public readonly float $gross,            // signed
        public readonly bool $isCredit,
        public readonly ?string $mydataState,    // null | VALID | CANCELLED
        public readonly ?string $mark,
        public readonly ?int $recordId = null,
        public readonly ?string $accountCode = null,   // ΕΓΛΣ default account (indicative)
        public readonly ?string $accountName = null,
        // Expense rows only: the economic bucket — 'suppliers' (myDATA sync),
        // 'manual', or the self-declared `expenses.category` key (payroll /
        // social_security / depreciation / …, see Codes::selfDeclaredVatCategoryKey).
        public readonly ?string $expenseBucket = null,
        // Income rows only: the tax the customer withheld on this document
        // (invoices.withhold_amount), signed like net — a credit note gives it back.
        public readonly float $withheld = 0.0,
    ) {}
}
