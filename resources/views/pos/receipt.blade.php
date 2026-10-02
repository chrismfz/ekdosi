<!doctype html>
<html lang="{{ $invoice->language ?? 'el' }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $invoice->invcode }}</title>
<style>
    @page { size: 80mm auto; margin: 0; }
    * { box-sizing: border-box; }
    body { width: 72mm; margin: 0 auto; padding: 3mm 0; font: 11px/1.35 "DejaVu Sans", Arial, sans-serif; color: #000; }
    .c { text-align: center; }
    .r { text-align: right; }
    .b { font-weight: 700; }
    .big { font-size: 15px; }
    hr { border: 0; border-top: 1px dashed #000; margin: 2mm 0; }
    table { width: 100%; border-collapse: collapse; }
    td { vertical-align: top; padding: .3mm 0; }
    .qr { width: 32mm; height: 32mm; display: block; margin: 1mm auto; }
    .small { font-size: 9px; word-break: break-all; }
    @media screen { body { background: #fff; box-shadow: 0 0 4px #aaa; padding: 4mm; margin: 4mm auto; } .noprint { margin: 3mm 0; text-align: center; } }
    @media print { .noprint { display: none; } }
</style>
</head>
<body>
    <div class="noprint"><button onclick="window.print()">Εκτύπωση</button></div>

    <div class="c">
        <div class="b big">{{ $tenant->name }}</div>
        @if($tenant->address){{ $tenant->address }}<br>@endif
        @if($tenant->city || $tenant->postcode){{ $tenant->postcode }} {{ $tenant->city }}<br>@endif
        @if($tenant->afm){{ $L('vat_no') }}: {{ $tenant->afm }}@if($tenant->tax_office) · {{ $L('tax_office') }} {{ $tenant->tax_office }}@endif<br>@endif
        @if($tenant->phone){{ $L('phone') }}: {{ $tenant->phone }}@endif
    </div>
    <hr>
    <div class="c b">@gup($invoice->invoiceType?->name ?? $L('doc_generic'))</div>
    <div class="c">{{ $invoice->invcode }} · {{ optional($invoice->issued_at)->format('d/m/Y H:i') }}</div>
    <hr>

    <table>
        @foreach($invoice->lines as $line)
            <tr><td colspan="2">{{ $line->product_descr }}</td></tr>
            <tr>
                <td>{{ rtrim(rtrim(number_format((float) $line->qty, 3, ',', '.'), '0'), ',') }} × {{ number_format(round((float) $line->gross_price / max((float) $line->qty, 0.001), 2), 2, ',', '.') }}@if((float) $line->discount > 0) (-{{ rtrim(rtrim(number_format((float) $line->discount, 2, ',', '.'), '0'), ',') }}%)@endif</td>
                <td class="r">{{ number_format((float) $line->gross_price, 2, ',', '.') }}</td>
            </tr>
        @endforeach
    </table>
    <hr>
    <table>
        @foreach($totals['rows'] as $row)
            <tr><td>{{ $L('vat') }} {{ rtrim(rtrim(number_format($row['rate'], 2, ',', '.'), '0'), ',') }}%: {{ number_format($row['net'], 2, ',', '.') }}</td><td class="r">{{ number_format($row['vat'], 2, ',', '.') }}</td></tr>
        @endforeach
        @foreach(['fees' => 'fees', 'stamp' => 'stamp_duty', 'other' => 'other_taxes'] as $key => $label)
            @if($totals[$key] > 0)
                <tr><td>{{ $L($label) }}</td><td class="r">+{{ number_format($totals[$key], 2, ',', '.') }}</td></tr>
            @endif
        @endforeach
        @foreach(['deductions' => 'deductions', 'withhold' => 'withholding'] as $key => $label)
            @if($totals[$key] > 0)
                <tr><td>{{ $L($label) }}</td><td class="r">−{{ number_format($totals[$key], 2, ',', '.') }}</td></tr>
            @endif
        @endforeach
        <tr class="b big"><td>@gup($L('total'))</td><td class="r">{{ number_format($totals['payable'], 2, ',', '.') }} €</td></tr>
        @if($invoice->paymentMethod)
            <tr><td colspan="2">{{ $invoice->paymentMethod->description }}</td></tr>
        @endif
    </table>

    @if(! empty($totals['vatExemption']))
        <hr>
        @foreach($totals['vatExemption'] as $ex)
            <div class="small">{{ $ex['label'] }}</div>
        @endforeach
    @endif

    @if($qrDataUri || $invoice->mydata_mark)
        <hr>
        @if($qrDataUri)<img class="qr" src="{{ $qrDataUri }}" alt="myDATA QR">@endif
        @if($invoice->mydata_mark)<div class="c">{{ $L('mark_label') }}: {{ $invoice->mydata_mark }}</div>@endif
    @endif

    @if(! empty($providerEvidence))
        <hr>
        <div class="small">
            {{ $L('provider_issued') }}: {{ $providerEvidence['commercial_name'] }}@if($providerEvidence['aade_code']) · ΑΑΔΕ {{ $providerEvidence['aade_code'] }}@endif<br>
            @if($providerEvidence['licence_no']){{ $L('provider_licence') }}: {{ $providerEvidence['licence_no'] }}<br>@endif
            @if($providerEvidence['uid']){{ $L('provider_uid') }}: {{ $providerEvidence['uid'] }}<br>@endif
            @if($providerEvidence['auth_code']){{ $L('provider_auth') }}: {{ $providerEvidence['auth_code'] }}@endif
        </div>
    @endif

    @if(! empty($tenant->pdf_footer_text))
        <hr><div class="c small">{{ $tenant->pdf_footer_text }}</div>
    @endif

    <script>
        window.addEventListener('load', () => { window.focus(); window.print(); });
    </script>
</body>
</html>
