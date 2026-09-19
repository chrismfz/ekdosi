@php use App\Support\Money; @endphp
@php
    /** @var \App\Support\Pdf\PdfLabels $L */
    // Defensive default: the renderer passes the customer-resolved $L, but the view
    // may be rendered directly (previews/tests). This blade has no Customer object
    // (only $customerName), so fall back to the tenant's default/country — non-fatal,
    // and the renderer's authoritative $L still wins on the real path.
    $L = $L ?? \App\Support\Pdf\PdfLabels::for(\App\Support\Pdf\PdfLabels::resolveLanguage($tenant?->default_language, $tenant?->country_code));
@endphp
<!DOCTYPE html>
<html lang="{{ $L->lang() === 'en' ? 'en' : 'el' }}">
<head>
    <meta charset="utf-8">
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        body { font-size: 12px; color: #1a1a1a; margin: 0; padding: 32px 40px; }
        .head { width: 100%; border-bottom: 2px solid #111; padding-bottom: 10px; margin-bottom: 18px; }
        .head td { vertical-align: top; }
        .logo { max-height: 60px; max-width: 200px; }
        .company { font-size: 15px; font-weight: bold; }
        .muted { color: #666; font-size: 11px; }
        h1 { font-size: 18px; margin: 8px 0 2px; letter-spacing: .5px; }
        .meta { width: 100%; margin: 14px 0 8px; }
        .meta td { padding: 3px 0; vertical-align: top; }
        .meta .k { color: #666; width: 34%; }
        table.lines { width: 100%; border-collapse: collapse; margin-top: 14px; }
        table.lines th, table.lines td { border-bottom: 1px solid #ddd; padding: 7px 6px; text-align: left; }
        table.lines th { background: #f4f4f4; font-size: 11px; }
        table.lines td.amt, table.lines th.amt { text-align: right; white-space: nowrap; }
        .total { margin-top: 10px; text-align: right; font-size: 15px; font-weight: bold; }
        .note { margin-top: 26px; color: #777; font-size: 10px; border-top: 1px solid #eee; padding-top: 8px; }
    </style>
</head>
<body>
    <table class="head">
        <tr>
            <td>
                <div class="company">{{ $tenant?->name }}</div>
                <div class="muted">
                    @if ($tenant?->afm) {{ $L('vat_no') }} {{ $tenant->afm }}@endif
                    @if ($tenant?->tax_office) · {{ $L('tax_office') }} {{ $tenant->tax_office }}@endif<br>
                    @if ($tenant?->address){{ $tenant->address }}@endif
                    @if ($tenant?->postcode) {{ $tenant->postcode }}@endif
                    @if ($tenant?->city) {{ $tenant->city }}@endif<br>
                    @if ($tenant?->phone){{ $L('phone') }}. {{ $tenant->phone }}@endif
                    @if ($tenant?->email) · {{ $tenant->email }}@endif
                </div>
            </td>
            <td style="text-align: right;">
                @if ($logoDataUri)
                    <img class="logo" src="{{ $logoDataUri }}" alt="logo">
                @endif
            </td>
        </tr>
    </table>

    <h1>{{ $L('receipt_title') }}</h1>
    <div class="muted">{{ $L('receipt_informal') }}</div>

    <table class="meta">
        <tr><td class="k">{{ $L('customer') }}</td><td>{{ $customerName }}@if ($customerAfm) · {{ $L('vat_no') }} {{ $customerAfm }}@endif</td></tr>
        <tr><td class="k">{{ $L('date_full') }}</td><td>{{ $date }}</td></tr>
        @if ($reference)<tr><td class="k">{{ $L('account_ref') }}</td><td>{{ $reference }}</td></tr>@endif
        <tr><td class="k">{{ $L('channel_method') }}</td><td>{{ $channel }}</td></tr>
        @if ($transactionId)<tr><td class="k">{{ $L('transaction_id') }}</td><td>{{ $transactionId }}</td></tr>@endif
    </table>

    <table class="lines">
        <thead>
            <tr><th>{{ $L('concerns') }}</th><th class="amt">{{ $L('amount_col') }}</th></tr>
        </thead>
        <tbody>
            @foreach ($lines as $line)
                <tr>
                    <td>{{ $line['label'] }}</td>
                    <td class="amt">{{ Money::eur($line['amount']) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="total">{{ $L('receipt_total') }}: {{ Money::eur($total) }}</div>

    <div class="note">
        {{ $L('receipt_disclaimer') }}
    </div>
</body>
</html>
