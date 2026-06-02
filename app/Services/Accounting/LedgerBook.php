<?php

namespace App\Services\Accounting;

use App\Models\Company;
use App\Models\Expense;
use App\Models\Invoice;
use App\Support\InvoiceScope;
use App\Support\MyData\Codes;
use Carbon\CarbonInterface;

/**
 * Βιβλίο Εσόδων-Εξόδων (απλογραφικά / Β' κατηγορίας) — a READ-ONLY projection
 * over the existing invoices (έσοδα) and expenses (έξοδα). No new table, no
 * write path: the "book" is just a chronological, classified view of documents
 * we already hold, with the totals the accountant needs. Full double-entry
 * (γενική λογιστική / Ομάδα 8) is deliberately out of scope — that lives in the
 * accountant's own software (Union κ.λπ.); we feed it clean classified data.
 *
 * Tenant-scoped EXPLICITLY by company_id on every query (Invoice/Expense have
 * only a no-op global scope outside panel context — see CLAUDE.md), mirroring
 * VatPeriodReport. "Live" follows the one predicate: InvoiceScope::live() for
 * sales, "not AADE-cancelled" for expenses. Credit notes are KEPT (listed) but
 * carry negative amounts so the period sums are net of them — the same sign
 * rule VatPeriodReport / the myDATA VAT picture use (Codes::CREDIT_NOTE_TYPES
 * for expenses; credited_invoice_id for our own credit notes).
 */
class LedgerBook
{
    public function __construct(private readonly Company $tenant) {}

    public function forPeriod(
        CarbonInterface $start,
        CarbonInterface $end,
        string $book = 'all',
        ?string $category = null,
    ): LedgerBookResult {
        $rows = [];

        if ($book === 'all' || $book === 'income') {
            $rows = array_merge($rows, $this->incomeRows($start, $end));
        }
        if ($book === 'all' || $book === 'expense') {
            $rows = array_merge($rows, $this->expenseRows($start, $end));
        }

        if ($category !== null && $category !== '') {
            $rows = array_values(array_filter($rows, fn (LedgerRow $r) => $r->categoryCode === $category));
        }

        usort($rows, function (LedgerRow $a, LedgerRow $b) {
            return [$a->date->getTimestamp(), $a->doc] <=> [$b->date->getTimestamp(), $b->doc];
        });

        return new LedgerBookResult(
            rows: $rows,
            periodLabel: $start->format('d/m/Y').' – '.$end->format('d/m/Y'),
        );
    }

    /** @return list<LedgerRow> */
    private function incomeRows(CarbonInterface $start, CarbonInterface $end): array
    {
        $query = Invoice::query()
            ->where('company_id', $this->tenant->getKey())
            ->where('issued_at', '>=', $start)
            ->where('issued_at', '<=', $end)
            ->with([
                'invoiceType:id,code,mydata_income_class_category',
                'customer:id,name,afm',
            ]);

        InvoiceScope::live($query);

        return $query->get()->map(function (Invoice $inv): LedgerRow {
            $isCredit = $inv->credited_invoice_id !== null;
            $sign = $isCredit ? -1 : 1;
            $net = $sign * (float) $inv->net_total;
            $gross = $sign * (float) $inv->gross_total;
            $code = $inv->invoiceType?->mydata_income_class_category;

            return new LedgerRow(
                book: 'income',
                date: $inv->issued_at,
                docType: $inv->invoiceType?->code ?? '',
                doc: $inv->invcode ?: (($inv->invoiceType?->code ?? '').$inv->code),
                counterparty: $inv->customer?->name,
                afm: $inv->customer?->afm,
                categoryCode: $code,
                categoryLabel: $code ? Codes::e3CategoryLabel($code) : null,
                net: round($net, 2),
                vat: round($gross - $net, 2),
                gross: round($gross, 2),
                isCredit: $isCredit,
                mydataState: $inv->mydata_state,
                mark: $inv->mydata_mark,
                recordId: $inv->getKey(),
            );
        })->all();
    }

    /** @return list<LedgerRow> */
    private function expenseRows(CarbonInterface $start, CarbonInterface $end): array
    {
        $creditTypes = Codes::CREDIT_NOTE_TYPES;

        $query = Expense::query()
            ->where('company_id', $this->tenant->getKey())
            ->whereNotNull('issue_date')
            ->whereBetween('issue_date', [$start->toDateString(), $end->toDateString()])
            ->where(fn ($q) => $q
                ->whereNull('mydata_state')
                ->orWhere('mydata_state', '!=', 'CANCELLED'))
            ->with('supplier:id,name,afm');

        return $query->get()->map(function (Expense $exp) use ($creditTypes): LedgerRow {
            $isCredit = in_array($exp->invoice_type, $creditTypes, true);
            $sign = $isCredit ? -1 : 1;
            $code = $exp->classification_category;

            $doc = trim(($exp->series ?? '').' '.($exp->aa ?? ''));
            if ($doc === '') {
                $doc = (string) ($exp->mydata_mark ?? '');
            }

            return new LedgerRow(
                book: 'expense',
                date: $exp->issue_date,
                docType: $exp->invoice_type ?? '',
                doc: $doc,
                counterparty: $exp->supplier?->name ?? $exp->supplier_name,
                afm: $exp->supplier?->afm ?? $exp->supplier_afm,
                categoryCode: $code,
                categoryLabel: $code ? Codes::e3CategoryLabel($code) : null,
                net: round($sign * (float) $exp->net_total, 2),
                vat: round($sign * (float) $exp->vat_total, 2),
                gross: round($sign * (float) $exp->gross_total, 2),
                isCredit: $isCredit,
                mydataState: $exp->mydata_state,
                mark: $exp->mydata_mark,
                recordId: $exp->getKey(),
            );
        })->all();
    }
}
