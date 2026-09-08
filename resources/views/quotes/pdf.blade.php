<!DOCTYPE html>
<html lang="el">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 1.5cm 1.4cm; }
        * { font-family: DejaVu Sans, sans-serif; }
        body { font-size: 11px; color: #1a1a1a; margin: 0; }

        .header { width: 100%; border-bottom: 2px solid #2c3e50; padding-bottom: 10px; margin-bottom: 14px; }
        .header td { vertical-align: top; }
        .logo { max-height: 70px; max-width: 220px; }
        .tenant-name { font-size: 17px; font-weight: bold; color: #2c3e50; }
        .tenant-meta { font-size: 10px; color: #555; line-height: 1.45; margin-top: 4px; }
        .doc-box { text-align: right; }
        .doc-title { font-size: 20px; font-weight: bold; color: #2c3e50; text-transform: uppercase; }
        .doc-meta { font-size: 10px; color: #444; margin-top: 6px; line-height: 1.5; }
        .doc-meta strong { color: #1a1a1a; }

        .proposal { margin-bottom: 14px; font-size: 11px; color: #333; line-height: 1.5; white-space: pre-wrap; }

        .parties { width: 100%; margin-bottom: 14px; }
        .parties td { width: 50%; vertical-align: top; padding: 0; }
        .party-card { border: 1px solid #ddd; border-radius: 5px; padding: 9px 11px; }
        .party-label { font-size: 9px; text-transform: uppercase; letter-spacing: .5px; color: #888; margin-bottom: 3px; }
        .party-name { font-size: 12px; font-weight: bold; color: #2c3e50; }
        .party-meta { font-size: 10px; color: #555; line-height: 1.5; margin-top: 2px; }

        table.lines { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
        table.lines thead th { background: #2c3e50; color: #fff; font-size: 9.5px; text-transform: uppercase; padding: 6px 7px; text-align: left; }
        table.lines tbody td { padding: 6px 7px; border-bottom: 1px solid #e8e8e8; font-size: 10.5px; vertical-align: top; }
        table.lines tbody tr:nth-child(even) { background: #f8f9fa; }
        .num { text-align: right; white-space: nowrap; }

        .totals { width: 48%; margin-left: 52%; border-collapse: collapse; }
        .totals td { padding: 4px 8px; font-size: 11px; }
        .totals .lbl { color: #555; }
        .totals .val { text-align: right; font-weight: bold; white-space: nowrap; }
        .totals .grand td { border-top: 2px solid #2c3e50; font-size: 13px; color: #2c3e50; padding-top: 6px; }

        .vat-break { width: 48%; border-collapse: collapse; font-size: 9.5px; }
        .vat-break th { text-align: right; color: #888; text-transform: uppercase; font-size: 8.5px; padding: 2px 8px; }
        .vat-break td { text-align: right; padding: 2px 8px; color: #555; }

        .notes { margin-top: 16px; border-top: 1px solid #ddd; padding-top: 8px; font-size: 10px; color: #555; white-space: pre-wrap; }
        .footer { margin-top: 20px; text-align: center; font-size: 9px; color: #999; border-top: 1px solid #eee; padding-top: 8px; }
    </style>
</head>
<body>

    {{-- Header: tenant identity + logo + document title/meta --}}
    <table class="header">
        <tr>
            <td style="width: 60%;">
                @if ($logoDataUri)
                    <img src="{{ $logoDataUri }}" class="logo" alt="logo">
                @endif
                <div class="tenant-name">{{ $tenant->name }}</div>
                <div class="tenant-meta">
                    @if ($tenant->afm){{ $L('vat_no') }}: {{ $tenant->afm }}@endif
                    @if ($tenant->tax_office) · {{ $L('tax_office') }}: {{ $tenant->tax_office }}@endif<br>
                    @if ($tenant->address){{ $tenant->address }}@endif
                    @if ($tenant->city), {{ $tenant->city }}@endif
                    @if ($tenant->postcode) {{ $tenant->postcode }}@endif<br>
                    @if ($tenant->phone){{ $L('phone') }}: {{ $tenant->phone }}@endif
                    @if ($tenant->email) · {{ $tenant->email }}@endif
                </div>
            </td>
            <td style="width: 40%;" class="doc-box">
                <div class="doc-title">@gup($L('quote_title'))</div>
                <div class="doc-meta">
                    <strong>{{ $quote->code }}</strong><br>
                    @if ($quote->subject){{ $quote->subject }}<br>@endif
                    {{ $L('date') }}: {{ $quote->issued_at?->format('d/m/Y') }}<br>
                    @if ($quote->valid_until){{ $L('valid_until') }}: {{ $quote->valid_until?->format('d/m/Y') }}@endif
                </div>
            </td>
        </tr>
    </table>

    {{-- Proposal text (top) --}}
    @if ($quote->proposal_text)
        <div class="proposal">{{ $quote->proposal_text }}</div>
    @endif

    {{-- Counterparty (customer) --}}
    <table class="parties">
        <tr>
            <td>
                <div class="party-card">
                    <div class="party-label">@gup($L('to'))</div>
                    <div class="party-name">{{ $quote->company_name ?: ($quote->customer?->name ?: '—') }}</div>
                    <div class="party-meta">
                        @if ($quote->vat_no){{ $L('vat_no') }}: {{ $quote->vat_no }}@endif
                        @if ($quote->occupation)<br>{{ $quote->occupation }}@endif
                        @if ($quote->address1)<br>{{ $quote->address1 }}@endif
                        @if ($quote->city), {{ $quote->city }}@endif
                        @if ($quote->postcode) {{ $quote->postcode }}@endif
                    </div>
                </div>
            </td>
            <td></td>
        </tr>
    </table>

    {{-- Line items --}}
    <table class="lines">
        <thead>
            <tr>
                <th style="width: 38px;">#</th>
                <th>@gup($L('description'))</th>
                <th style="width: 60px;" class="num">@gup($L('quantity_short'))</th>
                <th style="width: 75px;" class="num">@gup($L('unit_price'))</th>
                <th style="width: 45px;" class="num">@gup($L('vat_pct'))</th>
                <th style="width: 80px;" class="num">@gup($L('amount'))</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($quote->lines as $i => $line)
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td>{{ $line->product_descr }}</td>
                    <td class="num">{{ rtrim(rtrim(number_format((float) $line->qty, 3, ',', '.'), '0'), ',') }}</td>
                    <td class="num">{{ number_format((float) $line->price_per_item, 2, ',', '.') }} €</td>
                    <td class="num">{{ rtrim(rtrim(number_format((float) $line->vat_percent, 2, ',', '.'), '0'), ',') }}</td>
                    <td class="num">{{ number_format((float) $line->net_price, 2, ',', '.') }} €</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    {{-- VAT breakdown --}}
    <table class="vat-break">
        <tr>
            <th>@gup($L('net_value'))</th>
            <th>@gup($L('vat'))</th>
        </tr>
        @foreach ($totals['rows'] as $row)
            <tr>
                <td>{{ number_format($row['net'], 2, ',', '.') }} €</td>
                <td>{{ number_format($row['vat'], 2, ',', '.') }} € ({{ rtrim(rtrim(number_format($row['rate'], 2, ',', '.'), '0'), ',') }}%)</td>
            </tr>
        @endforeach
    </table>

    {{-- Totals --}}
    <table class="totals">
        <tr>
            <td class="lbl">{{ $L('net_value') }}</td>
            <td class="val">{{ number_format($totals['totalNet'], 2, ',', '.') }} €</td>
        </tr>
        <tr>
            <td class="lbl">{{ $L('vat') }}</td>
            <td class="val">{{ number_format($totals['totalVat'], 2, ',', '.') }} €</td>
        </tr>
        <tr class="grand">
            <td>{{ $L('total') }}</td>
            <td class="val">{{ number_format($totals['totalGross'], 2, ',', '.') }} €</td>
        </tr>
    </table>

    {{-- Customer notes (footer) --}}
    @if ($quote->customer_notes)
        <div class="notes">{{ $quote->customer_notes }}</div>
    @endif

    <div class="footer">
        {{ $tenant->name }} — {{ $L('quote_title') }} {{ $quote->code }} — {{ $L('quote_not_tax_doc') }}
    </div>

</body>
</html>
