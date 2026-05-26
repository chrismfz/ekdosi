<!DOCTYPE html>
<html lang="el">
<head>
    <meta charset="UTF-8">
    <title>{{ $invoice->invcode }}</title>
    <style>
        /* DomPDF supports a subset of CSS — keep this simple.
           PR #27 will replace this with a polished per-tenant template. */
        @page { margin: 18mm 14mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10pt; color: #222; }
        .row { display: table; width: 100%; table-layout: fixed; }
        .col-left  { display: table-cell; width: 55%; vertical-align: top; }
        .col-right { display: table-cell; width: 45%; vertical-align: top; text-align: right; }
        .draft { color: #b00; font-weight: bold; font-size: 18pt; border: 2px dashed #b00; padding: 4mm; text-align: center; margin-bottom: 6mm; }
        .filed { color: #060; }
        .h1 { font-size: 16pt; font-weight: bold; margin: 0; }
        .h2 { font-size: 12pt; font-weight: bold; margin: 0 0 2mm 0; }
        .muted { color: #666; font-size: 9pt; }
        .box { border: 1px solid #ccc; padding: 3mm; margin-bottom: 3mm; }
        table.lines { width: 100%; border-collapse: collapse; margin-top: 3mm; }
        table.lines th, table.lines td { border: 1px solid #ccc; padding: 2mm; vertical-align: top; font-size: 9pt; }
        table.lines th { background: #f5f5f5; text-align: left; }
        table.lines td.num { text-align: right; }
        table.totals { width: 100%; border-collapse: collapse; margin-top: 4mm; }
        table.totals td { padding: 1.5mm 3mm; }
        table.totals .label { color: #555; }
        table.totals .value { text-align: right; }
        table.totals .grand { font-weight: bold; font-size: 12pt; border-top: 2px solid #222; padding-top: 2.5mm; }
        .footer { margin-top: 8mm; font-size: 8pt; color: #777; }
        .qr-block { float: right; text-align: center; margin: 0 0 4mm 4mm; }
        .qr-block img { width: 30mm; height: 30mm; }
        .qr-block .mark { font-size: 8pt; color: #555; margin-top: 1mm; word-break: break-all; }
    </style>
</head>
<body>

@if($invoice->mydata_state === null)
    <div class="draft">ΠΡΟΧΕΙΡΟ — ΔΕΝ ΕΧΕΙ ΥΠΟΒΛΗΘΕΙ ΣΤΗ ΜΥDATA</div>
@elseif($invoice->mydata_state === 'CANCELLED')
    <div class="draft">ΑΚΥΡΩΘΕΝ ΠΑΡΑΣΤΑΤΙΚΟ</div>
@endif

<div class="row">
    <div class="col-left">
        <p class="h1">{{ $tenant->name ?? '' }}</p>
        <p class="muted">
            @if($tenant->address)  {{ $tenant->address }}<br>@endif
            @if($tenant->city || $tenant->postcode){{ $tenant->postcode }} {{ $tenant->city }}<br>@endif
            @if($tenant->afm)      ΑΦΜ: {{ $tenant->afm }}
                                   @if($tenant->tax_office)  · ΔΟΥ {{ $tenant->tax_office }} @endif
                                   <br>
            @endif
            @if($tenant->phone)    Τηλ: {{ $tenant->phone }} @endif
            @if($tenant->email)    · {{ $tenant->email }} @endif
        </p>
    </div>
    <div class="col-right">
        <p class="h1">{{ $invoice->invoiceType?->name ?? '' }}</p>
        <p class="h2">{{ $invoice->invcode }}</p>
        <p class="muted">{{ optional($invoice->issued_at)->format('d/m/Y H:i') }}</p>
    </div>
</div>

@if($qrDataUri)
    <div class="qr-block">
        <img src="{{ $qrDataUri }}" alt="myDATA QR">
        @if($invoice->mydata_mark)
            <div class="mark">MARK: {{ $invoice->mydata_mark }}</div>
        @endif
    </div>
@endif

<div class="box">
    <p class="h2">Στοιχεία Πελάτη</p>
    <strong>{{ $invoice->company_name ?: '—' }}</strong><br>
    @if($invoice->occupation)   {{ $invoice->occupation }}<br> @endif
    @if($invoice->address1)     {{ $invoice->address1 }}<br>   @endif
    @if($invoice->address2)     {{ $invoice->address2 }}<br>   @endif
    @if($invoice->city || $invoice->postcode)
        {{ $invoice->postcode }} {{ $invoice->city }}
        @if($invoice->country && $invoice->country !== 'GR') · {{ $invoice->country }} @endif
        <br>
    @endif
    @if($invoice->vat_no)       ΑΦΜ: {{ $invoice->vat_no }}<br> @endif
    @if($invoice->vies_vat)     VIES: {{ $invoice->vies_vat }}<br> @endif
    @if($invoice->paymentMethod)
        <span class="muted">Τρόπος πληρωμής: {{ $invoice->paymentMethod->description }}</span>
    @endif
</div>

<table class="lines">
    <thead>
        <tr>
            <th>Περιγραφή</th>
            <th>ΜΜ</th>
            <th class="num">Ποσότητα</th>
            <th class="num">Τιμή μονάδας</th>
            <th class="num">ΦΠΑ %</th>
            <th class="num">Αξία (καθαρή)</th>
            <th class="num">Αξία (με ΦΠΑ)</th>
        </tr>
    </thead>
    <tbody>
        @foreach($invoice->lines as $line)
            <tr>
                <td>
                    {{ $line->product_descr ?? '—' }}
                    @if($line->notes)<br><span class="muted">{{ $line->notes }}</span>@endif
                </td>
                <td>{{ $line->metric_unit }}</td>
                <td class="num">{{ number_format((float)$line->qty, 3, ',', '.') }}</td>
                <td class="num">{{ number_format((float)$line->price_per_item, 2, ',', '.') }}</td>
                <td class="num">{{ rtrim(rtrim(number_format((float)$line->vat_percent, 2, ',', '.'), '0'), ',') }}%</td>
                <td class="num">{{ number_format((float)$line->net_price, 2, ',', '.') }}</td>
                <td class="num">{{ number_format((float)$line->gross_price, 2, ',', '.') }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

<table class="totals">
    @foreach($totals['rows'] as $row)
        <tr>
            <td class="label">ΦΠΑ {{ rtrim(rtrim(number_format($row['rate'], 2, ',', '.'), '0'), ',') }}% επί καθ. {{ number_format($row['net'], 2, ',', '.') }}</td>
            <td class="value">{{ number_format($row['vat'], 2, ',', '.') }}</td>
        </tr>
    @endforeach
    <tr>
        <td class="label">Καθαρή Αξία</td>
        <td class="value">{{ number_format($totals['totalNet'], 2, ',', '.') }} €</td>
    </tr>
    <tr>
        <td class="label">Σύνολο ΦΠΑ</td>
        <td class="value">{{ number_format($totals['totalVat'], 2, ',', '.') }} €</td>
    </tr>
    @if(((float) $invoice->header_discount_percent) > 0)
        <tr>
            <td class="label muted">Έκπτωση παραστατικού: {{ number_format((float)$invoice->header_discount_percent, 2, ',', '.') }}% (εφαρμοσμένη)</td>
            <td class="value"></td>
        </tr>
    @endif
    <tr>
        <td class="label">Συνολική Αξία</td>
        <td class="value">{{ number_format($totals['totalGross'], 2, ',', '.') }} €</td>
    </tr>
    @if($totals['withhold'] > 0)
        <tr>
            <td class="label">Παρακράτηση</td>
            <td class="value">−{{ number_format($totals['withhold'], 2, ',', '.') }} €</td>
        </tr>
    @endif
    <tr>
        <td class="label grand">Πληρωτέο</td>
        <td class="value grand">{{ number_format($totals['payable'], 2, ',', '.') }} €</td>
    </tr>
</table>

@if($invoice->notes)
    <div class="box" style="margin-top:6mm">
        <p class="h2">Παρατηρήσεις</p>
        {!! nl2br(e($invoice->notes)) !!}
    </div>
@endif

<div class="footer">
    Παραστατικό εκδόθηκε ηλεκτρονικά από το σύστημα έκδοσης παραστατικών ekdosi.
    @if($invoice->mydata_url)
        Πιστοποιημένο στη myDATA — μπορείτε να το επαληθεύσετε σαρώνοντας το QR ή στη διεύθυνση:<br>
        <span class="muted">{{ $invoice->mydata_url }}</span>
    @endif
</div>

</body>
</html>
