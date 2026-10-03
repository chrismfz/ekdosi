<?php

namespace App\Actions;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\InvoiceType;
use App\Models\PosSession;
use App\Models\ReturnInvoiceExtra;
use App\Models\Scopes\CompanyScope;
use App\Services\Pos\PosIssuer;
use App\Services\Pos\TillSessions;
use App\Support\LineMoney;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * A till return / exchange (POS PR 2a, docs/woocommerce-bridge-plan.md §11.2):
 *
 * 1. a retail CREDIT NOTE (11.4, the company's POS credit series) for the selected
 *    quantities of the ORIGINAL receipt — through IssueCreditNote (correlated to the
 *    original, never more than was sold, the stock comes back, a shelf-priced line
 *    refunds exactly its shelf price) — issued on the spot like a sale;
 * 2. then, for an exchange, the NEW sale (CreatePosSale) — so the customer pays or
 *    gets back only the difference.
 *
 * Two legal documents, issued in that order. If the credit note fails nothing else
 * happens (its draft is kept, see PosSaleNotIssued); if the sale fails the credit
 * note STANDS (it is filed) and the error says so — the cashier refunds or retries.
 */
class CreatePosReturn
{
    public function __construct(
        private readonly IssueCreditNote $creditNotes,
        private readonly CreatePosSale $sales,
        private readonly PosIssuer $issuer,
    ) {}

    /**
     * @param  array<int|string, float|int|string>  $returnQty  original line id => qty to return
     * @param  list<array<string, mixed>>  $saleItems  CreatePosSale items (empty = plain return)
     * @return array{credit: Invoice, sale: ?Invoice}
     */
    public function __invoke(Company $company, Invoice $original, array $returnQty, array $saleItems = [], ?PosSession $session = null): array
    {
        $creditType = self::creditType($company);
        self::assertReturnable($company, $original);

        $selections = [];
        foreach ($returnQty as $lineId => $qty) {
            $qty = is_numeric($qty) ? round((float) $qty, 3) : 0.0;
            if ($qty > 0) {
                $selections[] = ['line_id' => (int) $lineId, 'qty' => $qty];
            }
        }
        if ($selections === []) {
            throw new RuntimeException('Διάλεξε τι επιστρέφεται (ποσότητα σε τουλάχιστον ένα είδος).');
        }

        // The new cart's own refusals (inactive item, no price, 0 € …) BEFORE the credit
        // note is filed — an input error must never leave a lone filed return behind.
        if ($saleItems !== []) {
            $this->sales->validate($company, $saleItems);
        }

        // Locked + validated against the remaining (already-returned) quantities.
        $credit = DB::transaction(function () use ($company, $original, $creditType, $selections, $session): Invoice {
            // «Ταμείο ημέρας» (PR 2b): the return belongs to the open session (locked).
            $sessionId = $session === null ? null : (int) app(TillSessions::class)->lockOpen($session, $company)->getKey();
            $credit = ($this->creditNotes)($original, $creditType, $selections);
            if ($sessionId !== null) {
                $credit->forceFill(['pos_session_id' => $sessionId])->saveQuietly();
            }

            return $credit;
        });
        $this->issuer->issue($credit);

        $sale = null;
        if ($saleItems !== []) {
            try {
                $sale = ($this->sales)($company, $saleItems, $session);
            } catch (\Throwable $e) {
                $draftId = $e instanceof PosSaleNotIssued ? $e->invoiceId : null;
                if ($draftId !== null) {
                    // No money was taken for it (the cashier refunds the return) — it
                    // must not count as drawer cash in the till's report (TillSessions).
                    Invoice::query()->withoutGlobalScope(CompanyScope::class)->whereKey($draftId)->update(['pos_session_id' => null]);
                }
                throw new PosExchangeIncomplete(
                    (int) $credit->getKey(),
                    (string) $credit->refresh()->invcode,
                    $e instanceof PosSaleNotIssued ? $e->invoiceId : null,
                    $e,
                );
            }
        }

        return ['credit' => $credit->refresh(), 'sale' => $sale];
    }

    /** The company's till credit-note series (11.4), or a clear refusal. */
    public static function creditType(Company $company): InvoiceType
    {
        if (! $company->hasPos()) {
            throw new RuntimeException('Το «Ταμείο» δεν είναι ενεργό για αυτή την εταιρεία.');
        }
        $type = $company->pos_credit_type_id === null ? null : InvoiceType::query()->withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->getKey())
            ->whereKey($company->pos_credit_type_id)
            ->first();
        if ($type === null || $type->mydata_type !== '11.4' || ! $type->is_credit) {
            throw new RuntimeException('Δεν έχει οριστεί σειρά πιστωτικών λιανικής (11.4) για επιστροφές στο Ταμείο.');
        }

        return $type;
    }

    /**
     * Only an issued, live receipt of THE TILL's series (pos_invoice_type_id) — never
     * another 11.x issued from «Παραστατικά» (a named customer, a credit-term method:
     * crediting it here would lower their balance AND hand out cash) — and only one
     * the till's refund math covers (no document discount, no document-level taxes).
     */
    public static function assertReturnable(Company $company, Invoice $original): void
    {
        if ((int) $original->company_id !== (int) $company->getKey()
            || $company->pos_invoice_type_id === null
            || (int) $original->invoice_type_id !== (int) $company->pos_invoice_type_id
            || $original->credited_invoice_id !== null
            || $original->customer_id !== null
            || (int) $original->payment_method_id !== (int) $company->pos_payment_method_id
            || ! $original->isReceiptPrintable()) {
            throw new RuntimeException('Δεν βρέθηκε εκδομένη απόδειξη του Ταμείου για επιστροφή.');
        }
        $documentTaxes = (float) $original->withhold_rate + (float) $original->fees_rate + (float) $original->other_taxes_rate
            + (float) $original->stamp_duty_rate + (float) $original->deductions_rate;
        if ((float) $original->header_discount_percent > 0 || $documentTaxes > 0) {
            throw new RuntimeException('Η απόδειξη έχει έκπτωση ή φόρους παραστατικού — η επιστροφή της γίνεται από τα «Παραστατικά».');
        }
    }

    /**
     * What each line of the original can still give back, and what it refunds per the
     * selected qty — the SAME formula the credit line will store (InvoiceLine::saving:
     * a shelf-priced line from its gross anchor, otherwise from its net), plus its
     * product-linked fee (the bag's fee comes back with the bag).
     *
     * @return list<array{line_id: int, label: string, remaining: float, unit: float, rate: float}>
     */
    public static function returnableLines(Invoice $original): array
    {
        $original->loadMissing('lines.product');
        $returned = ReturnInvoiceExtra::query()
            ->whereIn('invoice_line_id', $original->lines->pluck('id'))
            ->pluck('qty_returned', 'invoice_line_id');

        return $original->lines
            ->map(function (InvoiceLine $line) use ($returned): array {
                $rate = (float) $line->vat_percent;

                return [
                    'line_id' => (int) $line->id,
                    'label' => (string) $line->product_descr,
                    'remaining' => round((float) $line->qty - (float) ($returned[$line->id] ?? 0), 3),
                    'unit' => $line->gross_unit_price !== null
                        ? (float) $line->gross_unit_price
                        : (float) LineMoney::grossFromNet((float) $line->price_per_item, $rate),
                    'rate' => $rate,
                ];
            })
            ->filter(fn (array $l) => $l['remaining'] > 0.0001)
            ->values()
            ->all();
    }

    /** The refund of `$qty` of an original line (gross + its product fee), as the credit note will compute it. */
    public static function refundOf(InvoiceLine $line, float $qty): float
    {
        $rate = (float) $line->vat_percent;
        $discount = (float) ($line->discount ?? 0);
        $gross = $line->gross_unit_price !== null
            ? LineMoney::fromGross($qty, (float) $line->gross_unit_price, $discount, $rate)['gross']
            : LineMoney::fromNet($qty, (float) $line->price_per_item, $discount, $rate)['gross'];

        return round($gross + CreatePosSale::levyOf($line->product, $qty), 2);
    }
}
