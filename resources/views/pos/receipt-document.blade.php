{{-- ONE till document (sale 11.1 or return credit note 11.4) — the shell pos/receipt prints one or two of them. --}}
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
    @if($invoice->creditedInvoice)
        <div class="c">{{ $L('return_for') }}: {{ $invoice->creditedInvoice->invcode }}</div>
    @endif
    <hr>

    {{--
        One block per item: description, then «qty × unit (VAT incl.) · VAT%» and the
        amount BEFORE the line discount; a discount gets its own «Έκπτωση x%  −y» row
        (like a cash register); a document-level discount gets its own row too, so the
        item amounts add up to the VAT-inclusive total (fees/withholding follow it).
    --}}
    <table>
        @foreach($invoice->lines as $line)
            @php
                $qty = (float) $line->qty;
                $rate = (float) $line->vat_percent;
                $unitGross = $line->gross_unit_price !== null
                    ? (float) $line->gross_unit_price
                    : (float) \App\Support\LineMoney::grossFromNet((float) $line->price_per_item, $rate);
                $before = $line->gross_unit_price !== null
                    ? \App\Support\LineMoney::fromGross($qty, (float) $line->gross_unit_price, 0, $rate)['gross']
                    : \App\Support\LineMoney::fromNet($qty, (float) $line->price_per_item, 0, $rate)['gross'];
                // Only a REAL line discount gets a row — a legacy/imported line whose stored
                // gross is a rounding cent off the rebuilt one must not print «Έκπτωση 0%».
                $discountAmount = (float) $line->discount > 0 ? round($before - (float) $line->gross_price, 2) : 0.0;
            @endphp
            <tr><td colspan="2">{{ $line->product_descr }}</td></tr>
            <tr>
                <td>{{ rtrim(rtrim(number_format($qty, 3, ',', '.'), '0'), ',') }} × {{ number_format($unitGross, 2, ',', '.') }} · {{ rtrim(rtrim(number_format($rate, 2, ',', '.'), '0'), ',') }}%</td>
                <td class="r">{{ number_format($discountAmount > 0 ? $before : (float) $line->gross_price, 2, ',', '.') }}</td>
            </tr>
            @if($discountAmount > 0)
                <tr>
                    <td>&nbsp;&nbsp;{{ $L('line_discount') }} {{ rtrim(rtrim(number_format((float) $line->discount, 4, ',', '.'), '0'), ',') }}%</td>
                    <td class="r">−{{ number_format($discountAmount, 2, ',', '.') }}</td>
                </tr>
            @endif
        @endforeach
        @php($headerDiscount = round((float) $invoice->lines->sum(fn ($l) => (float) $l->gross_price) - (float) $totals['totalGross'], 2))
        @if((float) $invoice->header_discount_percent > 0 && $headerDiscount > 0)
            <tr>
                <td>{{ $L('header_discount') }} {{ rtrim(rtrim(number_format((float) $invoice->header_discount_percent, 4, ',', '.'), '0'), ',') }}%</td>
                <td class="r">−{{ number_format($headerDiscount, 2, ',', '.') }}</td>
            </tr>
        @endif
    </table>
    <hr>
    <table>
        {{-- Per VAT rate: the net it applies to and the VAT amount. --}}
        @foreach($totals['rows'] as $row)
            <tr><td>{{ $L('vat') }} {{ rtrim(rtrim(number_format($row['rate'], 2, ',', '.'), '0'), ',') }}% <span class="note">({{ $L('net') }} {{ number_format($row['net'], 2, ',', '.') }})</span></td><td class="r">{{ number_format($row['vat'], 2, ',', '.') }}</td></tr>
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
        <tr class="b big"><td>@gup($L($invoice->credited_invoice_id !== null ? 'refund_total' : 'total'))</td><td class="r">{{ number_format($totals['payable'], 2, ',', '.') }} €</td></tr>
        {{-- «Τεμάχια» only when every quantity is a whole number; kilos/hours → total quantity. --}}
        @php($wholeQty = $invoice->lines->every(fn ($l) => fmod((float) $l->qty, 1.0) == 0.0))
        <tr><td colspan="2">{{ $L($wholeQty ? 'items_count' : 'total_quantity') }}: {{ rtrim(rtrim(number_format((float) $totals['totalQty'], 3, ',', '.'), '0'), ',') }}</td></tr>
        @if($invoice->paymentMethod)
            <tr><td colspan="2">{{ $invoice->paymentMethod->description }}</td></tr>
        @endif
    </table>

    @if((float) $totals['totalVat'] > 0)
        <div class="c note">{{ $L('vat_included') }}</div>
    @endif

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

    {{-- The receipt's own barcode (ΜΑΡΚ or «R<id>»): a plain till scanner reads it back for a return. --}}
    @if($invoice->credited_invoice_id === null && \App\Support\Barcode::fitsCode128($invoice->receiptCode()))
        <hr>
        <img class="barcode" src="{{ \App\Support\Barcode::svgDataUri($invoice->receiptCode()) }}" alt="{{ $invoice->receiptCode() }}">
        <div class="c small">{{ $invoice->receiptCode() }}</div>
    @endif

    @if(! empty($tenant->pdf_footer_text))
        <hr><div class="c small">{{ $tenant->pdf_footer_text }}</div>
    @endif

