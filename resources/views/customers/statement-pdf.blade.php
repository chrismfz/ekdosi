@php
    /** @var \App\Models\Customer $customer */
    /** @var \App\Models\Company|null $company */
    /** @var \App\Support\Pdf\PdfLabels $L */
    // Defensive default: the renderer always passes the customer-resolved $L, but the
    // view may be rendered directly (previews/tests). Resolve the SAME live-customer
    // language here so a direct view() call never fatals on an undefined $L.
    $L = $L ?? \App\Support\Pdf\PdfLabels::for(\App\Support\CustomerLanguage::forCustomer($customer));
    $fmt = fn ($v) => \App\Support\Money::eur($v);
    $balance = (float) ($stats['balance'] ?? 0);
@endphp
<!DOCTYPE html>
<html lang="{{ $L->lang() === 'en' ? 'en' : 'el' }}">
<head>
    <meta charset="utf-8">
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        body { font-size: 11px; color: #1f2937; margin: 0; }
        h1 { font-size: 18px; margin: 0 0 2px; }
        .muted { color: #6b7280; }
        .right { text-align: right; }
        .header { border-bottom: 2px solid #111827; padding-bottom: 8px; margin-bottom: 12px; }
        .header td { vertical-align: top; }
        .balance-box { border: 1px solid #d1d5db; border-radius: 6px; padding: 8px 12px; }
        .balance-box .amount { font-size: 20px; font-weight: bold; }
        .danger { color: #b91c1c; }
        .success { color: #047857; }
        table.data { width: 100%; border-collapse: collapse; margin-top: 6px; }
        table.data th { background: #f3f4f6; text-align: left; padding: 5px 6px; border-bottom: 1px solid #d1d5db; font-size: 10px; text-transform: uppercase; color: #6b7280; }
        table.data td { padding: 4px 6px; border-bottom: 1px solid #eee; }
        .section-title { font-size: 12px; font-weight: bold; margin: 16px 0 4px; }
        .credit { color: #047857; }
        .badge { font-size: 9px; padding: 1px 5px; border-radius: 3px; background: #e5e7eb; }
    </style>
</head>
<body>
    <table class="header" width="100%">
        <tr>
            <td>
                <h1>{{ $company?->name ?? $L('statement_short') }}</h1>
                <div class="muted">
                    @if ($company?->afm){{ $L('vat_no') }}: {{ $company->afm }}@endif
                    @if ($company?->tax_office) &middot; {{ $L('tax_office') }}: {{ $company->tax_office }}@endif
                </div>
                @if ($company?->address)
                    <div class="muted">{{ $company->address }}{{ $company->city ? ', ' . $company->city : '' }} {{ $company->postcode }}</div>
                @endif
            </td>
            <td class="right" style="width: 220px;">
                <div class="muted">{{ $L('statement_title') }}</div>
                <div class="muted">{{ $L('issue_date') }}: {{ $generatedAt->format('d/m/Y H:i') }}</div>
            </td>
        </tr>
    </table>

    <table width="100%">
        <tr>
            <td style="vertical-align: top;">
                <div class="section-title" style="margin-top: 0;">{{ $L('customer') }}</div>
                <strong>{{ $customer->name }}</strong><br>
                @if ($customer->afm){{ $L('vat_no') }}: {{ $customer->afm }}<br>@endif
                @if ($customer->tax_office){{ $L('tax_office') }}: {{ $customer->tax_office }}<br>@endif
                @if ($customer->address1){{ $customer->address1 }}{{ $customer->city ? ', ' . $customer->city : '' }} {{ $customer->postcode }}@endif
            </td>
            <td class="right" style="width: 220px; vertical-align: top;">
                <div class="balance-box">
                    <div class="muted">{{ $L('balance') }}</div>
                    <div class="amount {{ $balance > 0 ? 'danger' : 'success' }}">{{ $fmt($balance) }}</div>
                    @if (! empty($stats['oldest_unpaid_days']))
                        <div class="muted">{{ $L('oldest_unpaid') }}: {{ $stats['oldest_unpaid_days'] }} {{ $L('days_short') }}</div>
                    @endif
                </div>
            </td>
        </tr>
    </table>

    @if (count($yearly) > 0)
        <div class="section-title">{{ $L('yearly_breakdown') }}</div>
        <table class="data">
            <thead>
                <tr>
                    <th>@gup($L('year'))</th>
                    <th class="right">@gup($L('invoices'))</th>
                    <th class="right">@gup($L('net_value'))</th>
                    <th class="right">@gup($L('gross_incl_vat'))</th>
                    <th class="right">@gup($L('payments'))</th>
                    <th class="right">@gup($L('year_end_balance'))</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($yearly as $row)
                    <tr>
                        <td>{{ $row['year'] }}</td>
                        <td class="right">{{ $row['invoice_count'] }}</td>
                        <td class="right">{{ $fmt($row['net']) }}</td>
                        <td class="right">{{ $fmt($row['gross']) }}</td>
                        <td class="right credit">{{ $fmt($row['paid']) }}</td>
                        <td class="right {{ $row['year_end_balance'] > 0 ? 'danger' : '' }}">{{ $fmt($row['year_end_balance']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <div class="section-title">{{ $L('ledger_movements') }}</div>
    <table class="data">
        <thead>
            <tr>
                <th>@gup($L('date_full'))</th>
                <th>@gup($L('type'))</th>
                <th>@gup($L('reference'))</th>
                <th class="right">@gup($L('debit'))</th>
                <th class="right">@gup($L('credit'))</th>
                <th class="right">@gup($L('balance'))</th>
                <th>myDATA</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($ledger as $row)
                <tr>
                    <td>{{ \Illuminate\Support\Carbon::parse($row['date'])->format('d/m/Y') }}</td>
                    <td><span class="badge">{{ \App\Services\CustomerLedger\CustomerLedgerBuilder::eventTypeLabel($row['type'], $row['invoice_type_code'] ?? null) }}</span></td>
                    <td>{{ ! empty($row['is_receipt_group']) && ! empty($row['allocations'])
                        ? \App\Services\CustomerLedger\ReceiptAllocationSummary::describe($row['reference'], $row['allocations'], $fmt)
                        : $row['reference'] }}
                        @php($adjDetail = \App\Services\CustomerLedger\CustomerLedgerBuilder::adjustmentDetail($row['document_gross'] ?? null, (float) ($row['tax_adjustment'] ?? 0), $fmt))
                        @if ($adjDetail)<div class="muted" style="font-size: 8pt;">{{ $adjDetail }}</div>@endif
                    </td>
                    <td class="right">{{ $row['debit'] > 0 ? $fmt($row['debit']) : '' }}</td>
                    <td class="right credit">{{ $row['credit'] > 0 ? $fmt($row['credit']) : '' }}</td>
                    <td class="right {{ $row['running_balance'] > 0 ? 'danger' : '' }}">{{ $fmt($row['running_balance']) }}</td>
                    <td>{{ $row['mydata_state'] ?? '' }}</td>
                </tr>
            @empty
                <tr><td colspan="7" class="muted">{{ $L('no_movements') }}</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
