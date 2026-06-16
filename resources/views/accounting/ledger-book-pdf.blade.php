@php
    /** @var \App\Services\Accounting\LedgerBookResult $result */
    /** @var \App\Models\Company|null $company */
    use App\Support\Money;
    $eur = fn ($v) => Money::eur($v);
    $num = fn ($v) => number_format((float) $v, 2, ',', '.');
    $net = round($result->incomeNet() - $result->expenseNet(), 2);
@endphp
<!DOCTYPE html>
<html lang="el">
<head>
    <meta charset="utf-8">
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        @page { margin: 14mm 10mm; }
        body { font-size: 8px; color: #1f2937; margin: 0; }
        h1 { font-size: 15px; margin: 0 0 2px; }
        .muted { color: #6b7280; }
        .right { text-align: right; }
        .header { border-bottom: 2px solid #111827; padding-bottom: 6px; margin-bottom: 8px; }
        .header td { vertical-align: top; }
        .sum { margin: 6px 0 10px; }
        .sum td { padding: 5px 9px; border: 1px solid #d1d5db; }
        .sum .label { color: #6b7280; font-size: 8px; }
        .sum .val { font-size: 12px; font-weight: bold; }
        .pos { color: #047857; } .neg { color: #b91c1c; }

        table.book { width: 100%; border-collapse: collapse; table-layout: fixed; }
        table.book th { background: #f3f4f6; text-align: left; padding: 3px 4px; border: 1px solid #d1d5db;
            font-size: 7px; text-transform: uppercase; color: #6b7280; }
        table.book td { padding: 3px 4px; border: 1px solid #eee; font-size: 7.5px; vertical-align: top;
            word-wrap: break-word; overflow-wrap: break-word; }
        table.book .num { text-align: right; }
        table.book .grp { text-align: center; background: #eef2ff; }
        table.book tfoot td { border-top: 2px solid #9ca3af; font-weight: bold; background: #f9fafb; }
        .credit { color: #b45309; }
        .mono { font-family: DejaVu Sans Mono, monospace; font-size: 7px; }
    </style>
</head>
<body>
    <table class="header" width="100%">
        <tr>
            <td>
                <h1>{{ $company?->name ?? 'Βιβλίο Εσόδων-Εξόδων' }}</h1>
                <div class="muted">
                    @if ($company?->afm)ΑΦΜ: {{ $company->afm }}@endif
                    @if ($company?->tax_office) &middot; ΔΟΥ: {{ $company->tax_office }}@endif
                </div>
            </td>
            <td class="right" style="width: 250px;">
                <div style="font-weight:bold;">Βιβλίο Εσόδων-Εξόδων</div>
                <div class="muted">Περίοδος: {{ $result->periodLabel }}</div>
                <div class="muted">Έκδοση: {{ $generatedAt->format('d/m/Y H:i') }}</div>
            </td>
        </tr>
    </table>

    {{-- Totals summary --}}
    <table class="sum" width="100%">
        <tr>
            <td style="width:25%;"><div class="label">Σύνολο Εσόδων ({{ $result->incomeCount() }})</div>
                <div class="val pos">{{ $eur($result->incomeNet()) }}</div>
                <div class="muted">ΦΠΑ {{ $num($result->incomeVat()) }}</div></td>
            <td style="width:25%;"><div class="label">Σύνολο Εξόδων ({{ $result->expenseCount() }})</div>
                <div class="val neg">{{ $eur($result->expenseNet()) }}</div>
                <div class="muted">ΦΠΑ {{ $num($result->expenseVat()) }}</div></td>
            <td style="width:25%;"><div class="label">Καθαρό αποτέλεσμα (έσοδα − έξοδα)</div>
                <div class="val {{ $net >= 0 ? 'pos' : 'neg' }}">{{ $eur($net) }}</div></td>
            <td style="width:25%;"><div class="label">ΦΠΑ εκροών − εισροών</div>
                <div class="val {{ $result->vatBalance() > 0 ? 'neg' : 'pos' }}">{{ $eur($result->vatBalance()) }}</div>
                <div class="muted">{{ $result->vatBalance() > 0 ? 'Προς απόδοση' : 'Πιστωτικό' }}</div></td>
        </tr>
    </table>

    <table class="book">
        <thead>
            <tr>
                <th style="width:6%;">Ημ/νία</th>
                <th style="width:8%;">Παραστατικό</th>
                <th style="width:11%;">ΜΑΡΚ</th>
                <th style="width:6%;">myDATA</th>
                <th style="width:5%;">Είδος</th>
                <th style="width:13%;">Αντισυμβαλλόμενος</th>
                <th style="width:7%;">ΑΦΜ</th>
                <th style="width:12%;">Κατηγορία</th>
                <th style="width:5%;">Λογ.</th>
                <th class="num grp" style="width:6.5%;">Έσοδα Καθ.</th>
                <th class="num grp" style="width:6.5%;">Έσοδα ΦΠΑ</th>
                <th class="num grp" style="width:6.5%;">Έξοδα Καθ.</th>
                <th class="num grp" style="width:6.5%;">Έξοδα ΦΠΑ</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($result->rows as $row)
                @php $isIncome = $row->book === 'income'; @endphp
                <tr>
                    <td>{{ $row->date->format('d/m/Y') }}</td>
                    <td>{{ $row->doc }}@if ($row->isCredit) <span class="credit">(πιστ.)</span>@endif</td>
                    <td class="mono">{{ $row->mark ?? '—' }}</td>
                    <td>{{ $row->mydataState ?? '—' }}</td>
                    <td>{{ $row->docType }}</td>
                    <td>{{ $row->counterparty ?? '—' }}</td>
                    <td>{{ $row->afm ?? '—' }}</td>
                    <td>{{ $row->categoryLabel ?? ($row->categoryCode ?? '—') }}</td>
                    <td>{{ $row->accountCode ?? '—' }}</td>
                    <td class="num">{{ $isIncome ? $num($row->net) : '' }}</td>
                    <td class="num">{{ $isIncome ? $num($row->vat) : '' }}</td>
                    <td class="num">{{ ! $isIncome ? $num($row->net) : '' }}</td>
                    <td class="num">{{ ! $isIncome ? $num($row->vat) : '' }}</td>
                </tr>
            @empty
                <tr><td colspan="13" style="text-align:center; padding:14px;" class="muted">Καμία εγγραφή στην περίοδο.</td></tr>
            @endforelse
        </tbody>
        @if (count($result->rows) > 0)
            <tfoot>
                <tr>
                    <td colspan="9" class="num">Σύνολα</td>
                    <td class="num">{{ $num($result->incomeNet()) }}</td>
                    <td class="num">{{ $num($result->incomeVat()) }}</td>
                    <td class="num">{{ $num($result->expenseNet()) }}</td>
                    <td class="num">{{ $num($result->expenseVat()) }}</td>
                </tr>
            </tfoot>
        @endif
    </table>
</body>
</html>
