<!DOCTYPE html>
<html lang="el">
<head>
    <meta charset="UTF-8">
    <title>{{ $invoice->invcode }}</title>
    {{--
        Polished single-template invoice PDF (PR #27). One Blade that
        adapts per invoice type via the AADE classification on
        $invoice->invoiceType->mydata_type:
            - 9.x  (Δελτίο Αποστολής)   → no totals, no payment, no QR-payable
            - 11.x (Λιανική/ΑΠΥ)        → no customer ΑΦΜ block (B2C)
            - 5.x  (Πιστωτικά)          → ΠΙΣΤΩΤΙΚΟ banner
            - 1.x/2.x (Standard B2B)    → full layout
        State banners (ΠΡΟΧΕΙΡΟ / ΑΚΥΡΩΘΕΝ) overlay any of the above.

        DomPDF subset of CSS only — no flexbox, no grid, no calc().
        Use table layouts and `display:table-cell`. Page-break control
        via `page-break-inside` on `.lines-wrap`.
    --}}
    <style>
        @page { margin: 16mm 14mm 22mm 14mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 9.5pt; color: #1f2937; line-height: 1.35; }

        /* Banners */
        .banner { text-align: center; font-weight: bold; font-size: 14pt; padding: 3mm; margin-bottom: 4mm; border: 2px solid; border-radius: 2mm; }
        .banner-draft     { color: #9a3412; border-color: #9a3412; background: #fff7ed; }
        .banner-cancelled { color: #7f1d1d; border-color: #7f1d1d; background: #fef2f2; }
        .banner-credit    { color: #1e40af; border-color: #1e40af; background: #eff6ff; }

        /* Header — tenant on the left, invoice meta on the right */
        .hdr { display: table; width: 100%; table-layout: fixed; border-bottom: 1.5pt solid #111827; padding-bottom: 3mm; margin-bottom: 4mm; }
        .hdr-left  { display: table-cell; width: 60%; vertical-align: top; }
        .hdr-right { display: table-cell; width: 40%; vertical-align: top; text-align: right; }
        .hdr-logo  { max-height: 22mm; max-width: 60mm; margin-bottom: 2mm; }
        .tenant-name { font-size: 13pt; font-weight: bold; margin: 0 0 1mm 0; }
        .tenant-info { font-size: 8.5pt; color: #4b5563; }
        /* clear:right so the (possibly multi-word) type name sits BELOW the floated
           QR instead of wrapping around it — fixes «ΠΙΣΤΩΤΙΚΟ» / «ΤΙΜΟΛΟΓΙΟ» splitting. */
        .doc-type    { font-size: 14pt; font-weight: bold; color: #111827; margin: 0; text-transform: uppercase; clear: right; }
        .doc-code    { font-size: 12pt; color: #111827; margin: 1mm 0; }
        .doc-date    { font-size: 9pt; color: #4b5563; }

        /* Two-column meta strip (customer / invoice details) */
        .meta { display: table; width: 100%; table-layout: fixed; margin-bottom: 4mm; }
        .meta-cell { display: table-cell; width: 50%; vertical-align: top; padding: 3mm; border: 1pt solid #d1d5db; border-radius: 1mm; }
        .meta-cell + .meta-cell { border-left: none; }
        .meta-cell h3 { font-size: 8pt; text-transform: uppercase; letter-spacing: 0.5pt; color: #6b7280; margin: 0 0 1.5mm 0; font-weight: bold; }
        .meta-cell .name { font-weight: bold; font-size: 10.5pt; margin-bottom: 1mm; }
        .meta-row { font-size: 9pt; color: #1f2937; }
        .meta-label { color: #6b7280; }

        /* QR block floats over the meta strip on filed invoices */
        .qr-block { float: right; text-align: center; margin: 0 0 3mm 4mm; padding: 2mm; border: 1pt solid #e5e7eb; border-radius: 1mm; background: #fafafa; }
        .qr-block img { width: 32mm; height: 32mm; display: block; }
        .qr-block .qr-label { font-size: 7pt; color: #6b7280; margin: 1mm 0 0 0; }
        .qr-block .qr-mark  { font-size: 7pt; color: #374151; word-break: break-all; max-width: 32mm; }

        /* Provider (ΥΠΑΗΕΣ) evidence block — PROV-003 / A.1112/2025 */
        .provider-box { clear: both; border: 1pt solid #d1d5db; border-radius: 1.5mm; background: #f9fafb; padding: 2.5mm 3mm; margin: 0 0 3mm 0; font-size: 7.5pt; color: #374151; }
        .provider-box .provider-title { font-weight: 700; color: #111827; margin: 0 0 1mm 0; font-size: 8pt; }
        .provider-box .provider-row { margin: 0 0 0.6mm 0; }
        .provider-box .provider-label { color: #6b7280; }
        .provider-box .provider-auth { word-break: break-all; overflow-wrap: anywhere; }

        /* Lines table */
        .lines-wrap { page-break-inside: auto; }
        table.lines { width: 100%; border-collapse: collapse; }
        table.lines thead th { background: #f3f4f6; border-bottom: 1pt solid #9ca3af; padding: 2mm; font-size: 8.5pt; font-weight: bold; text-transform: uppercase; letter-spacing: 0.3pt; color: #374151; text-align: left; }
        table.lines tbody td { padding: 2mm; border-bottom: 0.5pt solid #e5e7eb; font-size: 9pt; vertical-align: top; }
        table.lines tbody tr { page-break-inside: avoid; }
        table.lines td.num, table.lines th.num { text-align: right; }
        table.lines td.center, table.lines th.center { text-align: center; }
        /* font-weight MUST be 400 or 700 -- DomPDF cant match intermediate
           weights against DejaVu Sans (which only ships Book + Bold) and
           silently falls back to a core-14 font like Helvetica, which has
           no Greek glyphs -- Greek text in this cell then renders as ?. */
        .line-desc { font-weight: normal; }
        .line-notes { font-size: 8pt; color: #6b7280; margin-top: 0.5mm; font-style: italic; }

        /* Totals — right-aligned summary box */
        .totals-wrap { display: table; width: 100%; table-layout: fixed; margin-top: 4mm; }
        .totals-spacer { display: table-cell; width: 45%; }
        .totals-box { display: table-cell; width: 55%; vertical-align: top; }
        table.totals { width: 100%; border-collapse: collapse; }
        table.totals td { padding: 1.5mm 3mm; font-size: 9.5pt; }
        table.totals .vat-row td { color: #4b5563; font-size: 8.5pt; }
        table.totals .label { color: #374151; }
        table.totals .value { text-align: right; }
        table.totals .subtotal td { border-top: 0.5pt solid #d1d5db; padding-top: 2mm; }
        table.totals .grand { background: #111827; color: #fff; font-weight: bold; font-size: 11pt; }
        table.totals .grand td { padding: 2.5mm 3mm; }
        table.totals .withhold td { color: #9a3412; font-style: italic; }
        table.totals .discount-note td { color: #6b7280; font-size: 8pt; font-style: italic; padding-top: 0; }

        /* Customer running-balance block (legacy «ΝΕΟ ΥΠΟΛΟΙΠΟ») */
        .balance-wrap { display: table; width: 100%; table-layout: fixed; margin-top: 4mm; }
        .balance-spacer { display: table-cell; width: 45%; }
        .balance-box { display: table-cell; width: 55%; vertical-align: top; border: 0.5pt solid #d1d5db; border-radius: 1mm; }
        .balance-box h3 { margin: 0; padding: 1.5mm 3mm; font-size: 8pt; text-transform: uppercase; letter-spacing: 0.3pt; color: #6b7280; background: #f3f4f6; border-bottom: 0.5pt solid #d1d5db; }
        table.balance { width: 100%; border-collapse: collapse; }
        table.balance td { padding: 1.5mm 3mm; font-size: 9.5pt; }
        table.balance .label { color: #374151; }
        table.balance .value { text-align: right; }
        table.balance .new td { border-top: 0.5pt solid #d1d5db; font-weight: bold; }

        /* Payment accounts list (several IBANs) + total-quantity line */
        .bank-row { padding-left: 3mm; font-size: 8.5pt; color: #374151; }
        .qty-total { text-align: right; font-size: 8.5pt; color: #374151; margin-top: 1.5mm; }

        /* Notes / payment terms */
        .notes-box { margin-top: 5mm; padding: 3mm; background: #f9fafb; border-left: 3pt solid #6b7280; font-size: 9pt; }
        .notes-box h3 { margin: 0 0 1mm 0; font-size: 8.5pt; text-transform: uppercase; color: #6b7280; letter-spacing: 0.3pt; }

        /* Footer with verification + tenant text — rendered as a fixed
           bottom-margin block by DomPDF via the page-bottom margin. */
        .footer { position: fixed; left: 0; right: 0; bottom: -16mm; text-align: center; font-size: 7.5pt; color: #6b7280; padding: 0 14mm; }
        .footer .mydata-line { margin-bottom: 1mm; color: #374151; }
        .footer .mydata-url { word-break: break-all; overflow-wrap: anywhere; font-size: 7pt; color: #6b7280; }
        .footer .tenant-text { margin-top: 1mm; font-style: italic; }
        .pager:after { content: counter(page); }
        .pager-total:after { content: counter(pages); }

        /* Related documents (credit-note / delivery links), printed when present */
        .related { margin-top: 6mm; padding: 3mm; border: 1pt solid #e5e7eb; border-radius: 1mm; background: #fafafa; page-break-inside: avoid; }
        .related h3 { font-size: 8.5pt; text-transform: uppercase; letter-spacing: 0.3pt; color: #6b7280; margin: 0 0 1.5mm 0; font-weight: bold; }
        .related .rel-row { font-size: 9pt; color: #1f2937; margin: 0.8mm 0; }
        .related .rel-label { color: #6b7280; }
        .related .rel-badge { font-weight: bold; color: #7f1d1d; }
        .related .rel-note { font-size: 7.5pt; color: #6b7280; margin: 0.3mm 0 1.5mm; }
    </style>
</head>
<body>

{{-- ====================== State + classification banners ====================== --}}
@php
    $mydataType = $invoice->invoiceType?->mydata_type ?? '';
    $isDelivery = str_starts_with($mydataType, '9.');
    $isRetail   = str_starts_with($mydataType, '11.');
    $isCredit   = str_starts_with($mydataType, '5.') || ($invoice->invoiceType?->is_credit ?? false);
    $isReturn   = $invoice->invoiceType?->is_return ?? false;
    // DOC-2/DOC-3: banner = f(local_status × mydata_state × provider), not
    // mydata_state alone — see InvoiceBannerState for the matrix.
    $bannerState = \App\Support\Pdf\InvoiceBannerState::for($invoice);
    $isCancelledDoc = $bannerState['kind'] === 'cancelled';
@endphp

@if($bannerState['kind'] === 'cancelled')
    <div class="banner banner-cancelled">
        {{ $L('banner_cancelled') }}@if($bannerState['note'] === 'cancel_pending_mydata')<br><span style="font-size:8.5pt; font-weight:400">{{ $L('banner_cancel_pending_mydata') }}</span>@endif
    </div>
@elseif($bannerState['kind'] === 'draft')
    <div class="banner banner-draft">{{ $L('banner_draft') }}</div>
@elseif($bannerState['kind'] === 'pending_mydata')
    <div class="banner banner-draft">{{ $L('banner_pending_mydata') }}</div>
@elseif($isCredit)
    <div class="banner banner-credit">{{ $L('banner_credit') }}</div>
@endif

{{-- ====================== Header: logo + tenant info | invoice meta ====================== --}}
<div class="hdr">
    <div class="hdr-left">
        @if(! empty($logoDataUri))
            <img src="{{ $logoDataUri }}" alt="" class="hdr-logo">
        @endif
        <p class="tenant-name">{{ $tenant->name ?? '' }}</p>
        <p class="tenant-info">
            @if($tenant->address) {{ $tenant->address }}<br> @endif
            @if($tenant->city || $tenant->postcode){{ $tenant->postcode }} {{ $tenant->city }}<br>@endif
            @if($tenant->afm)
                {{ $L('vat_no') }}: {{ $tenant->afm }}@if($tenant->tax_office) · {{ $L('tax_office') }} {{ $tenant->tax_office }}@endif
                <br>
            @endif
            {{-- DOC-4: ΓΕΜΗ (ν.4919/2022 αρ.22) + issuer activity/ΚΑΔ on the header. --}}
            @if($tenant->gemi){{ $L('gemi') }}: {{ $tenant->gemi }}@if($tenant->kad_primary) · {{ $L('activity') }} {{ $tenant->kad_primary }}@endif<br>
            @elseif($tenant->kad_primary){{ $L('activity') }}: {{ $tenant->kad_primary }}<br>
            @endif
            @if($tenant->phone) {{ $L('phone') }}: {{ $tenant->phone }} @endif
            @if($tenant->email) · {{ $tenant->email }} @endif
        </p>
    </div>
    <div class="hdr-right">
        {{-- DOC-5: the ΜΑΡΚ prints whenever the invoice carries one, decoupled
             from the QR image. An ETL-imported legacy invoice is VALID with a
             mydata_mark but NO mydata_url (→ no QR): it must still show its ΜΑΡΚ
             (with a «ΜΑΡΚ:» label since the QR that would give it context is
             absent) rather than come out bare. QR renders only when a url exists. --}}
        @if($qrDataUri || $invoice->mydata_mark)
            <div class="qr-block">
                @if($qrDataUri)
                    <img src="{{ $qrDataUri }}" alt="myDATA QR">
                    <p class="qr-label">myDATA</p>
                @endif
                @if($invoice->mydata_mark)
                    <p class="qr-mark">@if(! $qrDataUri){{ $L('mark_label') }}: @endif{{ $invoice->mydata_mark }}</p>
                @endif
            </div>
        @endif
        <p class="doc-type">@gup($invoice->invoiceType?->name ?? $L('doc_generic'))</p>
        <p class="doc-code">{{ $invoice->invcode }}</p>
        <p class="doc-date">
            {{ optional($invoice->issued_at)->format('d/m/Y H:i') }}
            @if($invoice->delivery_date && $isDelivery)
                <br><span class="meta-label">{{ $L('delivery_date') }}:</span> {{ $invoice->delivery_date->format('d/m/Y') }}
            @endif
        </p>
    </div>
</div>

{{-- ====================== Customer + invoice meta strip ====================== --}}
{{-- Delivery notes and retail receipts skip the full customer block --}}
@if(! $isRetail || $invoice->vat_no)
    <div class="meta">
        <div class="meta-cell">
            <h3>@gup($L('customer_details'))</h3>
            <div class="name">{{ $invoice->company_name ?: '—' }}</div>
            <div class="meta-row">
                @if($invoice->occupation) {{ $invoice->occupation }}<br> @endif
                @if($invoice->address1) {{ $invoice->address1 }}<br> @endif
                @if($invoice->address2) {{ $invoice->address2 }}<br> @endif
                @if($invoice->city || $invoice->postcode){{ $invoice->postcode }} {{ $invoice->city }}@endif
                @if($invoice->country && $invoice->country !== 'GR') · {{ $invoice->country }} @endif
                @if($invoice->vat_no)<br>{{ $L('vat_no') }}: {{ $invoice->vat_no }}@endif
                @if($invoice->vies_vat)<br>VIES: {{ $invoice->vies_vat }}@endif
            </div>
        </div>
        <div class="meta-cell">
            <h3>@gup($L('doc_terms'))</h3>
            @if($invoice->paymentMethod && ! $isDelivery)
                <div class="meta-row"><span class="meta-label">{{ $L('payment_method') }}:</span> {{ $invoice->paymentMethod->description }}</div>
            @endif
            @if(! $isDelivery && ($bankAccounts ?? collect())->isNotEmpty())
                <div class="meta-row"><span class="meta-label">{{ $L('payment_accounts') }}:</span></div>
                @foreach($bankAccounts as $acc)
                    <div class="meta-row bank-row">{{ $acc->bank_name }}@if($acc->iban) — {{ $acc->iban }}@endif@if($acc->swift) ({{ $acc->swift }})@endif</div>
                @endforeach
            @endif
            @if($invoice->deliveryMethod ?? null)
                <div class="meta-row"><span class="meta-label">{{ $L('shipping_method') }}:</span> {{ $invoice->deliveryMethod->description }}</div>
            @endif
            @if($invoice->distributionAim ?? null)
                <div class="meta-row"><span class="meta-label">{{ $L('movement_purpose') }}:</span> {{ $invoice->distributionAim->description }}</div>
            @endif
            @if($invoice->invoiceType?->mydata_type)
                <div class="meta-row"><span class="meta-label">{{ $L('mydata_type') }}:</span> {{ $invoice->invoiceType->mydata_type }}</div>
            @endif
            {{-- DOC-7: never assert «Πιστοποιημένο» on a cancelled document —
                 a locally-voided invoice can still be VALID at AADE.
                 DOC-5: a VALID ΜΑΡΚ IS certified regardless of a verify URL, so
                 this does NOT require mydata_url (an ETL-imported legacy invoice
                 has the mark but no url). The footer «verify at URL» line stays
                 url-gated — it legitimately needs the link. --}}
            @if($invoice->mydata_state === 'VALID' && ! $isCancelledDoc)
                <div class="meta-row"><span class="meta-label">{{ $L('status') }}:</span> <strong style="color:#065f46">{{ $L('certified') }}</strong></div>
            @endif
        </div>
    </div>
@endif

{{-- ============ Provider (ΥΠΑΗΕΣ) evidence — PROV-003 / A.1112/2025 ============ --}}
{{-- Printed only for a document actually filed through a provider (a PROVIDER_INSERT
     MARK that IS the current filing) and still VALID/not-cancelled with a configured
     provider licence. That whole decision lives in InvoicePdfRenderer::providerEvidenceView
     (single source) — here we only render what it computed. --}}
@if(! empty($providerEvidence))
    <div class="provider-box">
        <div class="provider-title">{{ $L('provider_issued') }}</div>
        <div class="provider-row">
            <span class="provider-label">{{ $L('provider_name') }}:</span>
            {{ $providerEvidence['commercial_name'] }}@if($providerEvidence['legal_name']) — {{ $providerEvidence['legal_name'] }}@endif@if($providerEvidence['aade_code']) · ΑΑΔΕ {{ $providerEvidence['aade_code'] }}@endif@if($providerEvidence['site']) · {{ $providerEvidence['site'] }}@endif
        </div>
        @if($providerEvidence['licence_no'])
            <div class="provider-row"><span class="provider-label">{{ $L('provider_licence') }}:</span> {{ $providerEvidence['licence_no'] }}</div>
        @endif
        <div class="provider-row"><span class="provider-label">{{ $L('mark_label') }}:</span> {{ $providerEvidence['mark'] }}</div>
        @if($providerEvidence['uid'])
            <div class="provider-row"><span class="provider-label">{{ $L('provider_uid') }}:</span> {{ $providerEvidence['uid'] }}</div>
        @endif
        @if($providerEvidence['auth_code'])
            <div class="provider-row"><span class="provider-label">{{ $L('provider_auth') }}:</span> <span class="provider-auth">{{ $providerEvidence['auth_code'] }}</span></div>
        @endif
    </div>
@endif

{{-- ====================== Lines ====================== --}}
<div class="lines-wrap">
    <table class="lines">
        <thead>
            <tr>
                <th style="width: 38%">@gup($L('description'))</th>
                <th class="center" style="width: 8%">@gup($L('unit'))</th>
                <th class="num" style="width: 10%">@gup($L('quantity'))</th>
                @if(! $isDelivery)
                    <th class="num" style="width: 12%">@gup($L('unit_price'))</th>
                    @php $anyDiscount = $invoice->lines->contains(fn($l) => (float)$l->discount > 0); @endphp
                    @if($anyDiscount)
                        <th class="num" style="width: 7%">@gup($L('discount_pct'))</th>
                    @endif
                    <th class="num" style="width: 7%">@gup($L('vat_pct'))</th>
                    <th class="num" style="width: 11%">@gup($L('net'))</th>
                    <th class="num" style="width: 12%">@gup($L('gross_incl_vat'))</th>
                @endif
            </tr>
        </thead>
        <tbody>
            @forelse($invoice->lines as $line)
                <tr>
                    <td>
                        <div class="line-desc">{{ $line->product_descr ?? '—' }}</div>
                        @if($line->notes)<div class="line-notes">{{ $line->notes }}</div>@endif
                    </td>
                    <td class="center">{{ $line->metric_unit }}</td>
                    <td class="num">{{ number_format((float)$line->qty, 3, ',', '.') }}</td>
                    @if(! $isDelivery)
                        <td class="num">{{ number_format((float)$line->price_per_item, 2, ',', '.') }}</td>
                        @if($anyDiscount)
                            <td class="num">{{ (float)$line->discount > 0 ? rtrim(rtrim(number_format((float)$line->discount, 2, ',', '.'), '0'), ',').'%' : '—' }}</td>
                        @endif
                        <td class="num">{{ rtrim(rtrim(number_format((float)$line->vat_percent, 2, ',', '.'), '0'), ',') }}%</td>
                        <td class="num">{{ number_format((float)$line->net_price, 2, ',', '.') }}</td>
                        <td class="num">{{ number_format((float)$line->gross_price, 2, ',', '.') }}</td>
                    @endif
                </tr>
            @empty
                <tr><td colspan="8" style="text-align:center; color:#9ca3af; font-style:italic">— {{ $L('no_lines') }} —</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

{{-- Συνολική ποσότητα (άθροισμα τεμαχίων όλων των γραμμών) — shown for both
     invoices and the delivery variant, like a standard Greek τιμολόγιο. --}}
@if($invoice->lines->isNotEmpty())
    <div class="qty-total">{{ $L('total_quantity') }}: <strong>{{ number_format((float) ($totals['totalQty'] ?? 0), 3, ',', '.') }}</strong></div>
@endif

{{-- ====================== Totals (skipped for delivery notes) ====================== --}}
@if(! $isDelivery)
    <div class="totals-wrap">
        <div class="totals-spacer"></div>
        <div class="totals-box">
            <table class="totals">
                @foreach($totals['rows'] as $row)
                    <tr class="vat-row">
                        <td class="label">{{ $L('vat') }} {{ rtrim(rtrim(number_format($row['rate'], 2, ',', '.'), '0'), ',') }}% {{ $L('on_net') }} {{ number_format($row['net'], 2, ',', '.') }}</td>
                        <td class="value">{{ number_format($row['vat'], 2, ',', '.') }}</td>
                    </tr>
                @endforeach
                {{-- DOC-1: a 0% παραστατικό must cite the exempting provision (ΕΛΠ
                     ν.4308/2014 αρ.9). The citation is the verbatim §8.3 legal text. --}}
                @if(!empty($totals['vatExemption']))
                    @foreach($totals['vatExemption'] as $ex)
                        <tr class="discount-note">
                            <td colspan="2">{{ $L('vat_exemption') }}: {{ $ex['label'] }} (myDATA §8.3-{{ $ex['code'] }})</td>
                        </tr>
                    @endforeach
                @endif
                <tr class="subtotal">
                    <td class="label">{{ $L('net_value') }}</td>
                    <td class="value">{{ number_format($totals['totalNet'], 2, ',', '.') }} €</td>
                </tr>
                <tr>
                    <td class="label">{{ $L('total_vat') }}</td>
                    <td class="value">{{ number_format($totals['totalVat'], 2, ',', '.') }} €</td>
                </tr>
                @if(((float) $invoice->header_discount_percent) > 0)
                    <tr class="discount-note">
                        <td>{{ $L('header_discount') }} {{ number_format((float)$invoice->header_discount_percent, 2, ',', '.') }}% ({{ $L('applied') }})</td>
                        <td></td>
                    </tr>
                @endif
                <tr class="grand">
                    <td>{{ $L('total_value') }}</td>
                    <td class="value">{{ number_format($totals['totalGross'], 2, ',', '.') }} €</td>
                </tr>
                {{-- Additional taxes (τέλη/ψηφιακό τέλος συναλλαγής/παρακράτηση…): + charges, − reductions.
                     «Πληρωτέο» shows the collectible whenever it differs from the gross. --}}
                @if($totals['fees'] > 0)
                    <tr class="withhold"><td class="label">{{ $L('fees') }}</td><td class="value">+{{ number_format($totals['fees'], 2, ',', '.') }} €</td></tr>
                @endif
                @if($totals['stamp'] > 0)
                    <tr class="withhold"><td class="label">{{ $L('stamp_duty') }}</td><td class="value">+{{ number_format($totals['stamp'], 2, ',', '.') }} €</td></tr>
                @endif
                @if($totals['other'] > 0)
                    <tr class="withhold"><td class="label">{{ $L('other_taxes') }}</td><td class="value">+{{ number_format($totals['other'], 2, ',', '.') }} €</td></tr>
                @endif
                @if($totals['deductions'] > 0)
                    <tr class="withhold"><td class="label">{{ $L('deductions') }}</td><td class="value">−{{ number_format($totals['deductions'], 2, ',', '.') }} €</td></tr>
                @endif
                @if($totals['withhold'] > 0)
                    <tr class="withhold"><td class="label">{{ $L('withholding') }}</td><td class="value">−{{ number_format($totals['withhold'], 2, ',', '.') }} €</td></tr>
                @endif
                @if(abs($totals['payable'] - $totals['totalGross']) > 0.005)
                    <tr class="grand">
                        <td>{{ $L('payable') }}</td>
                        <td class="value">{{ number_format($totals['payable'], 2, ',', '.') }} €</td>
                    </tr>
                @endif
            </table>
        </div>
    </div>
@endif

{{-- ============== Υπόλοιπο πελάτη (legacy «ΝΕΟ ΥΠΟΛΟΙΠΟ») ==============
     Snapshot-at-issue running balance: Προηγούμενο + αυτό το παραστατικό = Νέο.
     Printed only when the tenant/customer opted in AND a snapshot was captured
     (credit-term/credit-note invoice). Stable on reprint. --}}
@if(($customerBalance ?? null) !== null)
    <div class="balance-wrap">
        <div class="balance-spacer"></div>
        <div class="balance-box">
            <h3>@gup($L('customer_balance'))</h3>
            <table class="balance">
                {{-- A negative balance = customer in credit; use the U+2212 minus
                     to match the rest of the document (deductions/withholding rows). --}}
                <tr>
                    <td class="label">{{ $L('previous_balance') }}</td>
                    <td class="value">{{ str_replace('-', '−', number_format($customerBalance['previous'], 2, ',', '.')) }} €</td>
                </tr>
                <tr>
                    <td class="label">{{ $L('this_document') }}</td>
                    <td class="value">{{ ($customerBalance['current'] >= 0 ? '+' : '−') }}{{ number_format(abs($customerBalance['current']), 2, ',', '.') }} €</td>
                </tr>
                <tr class="new">
                    <td class="label">{{ $L('new_balance') }}</td>
                    <td class="value">{{ str_replace('-', '−', number_format($customerBalance['new'], 2, ',', '.')) }} €</td>
                </tr>
            </table>
        </div>
    </div>
@endif

{{-- ====================== Notes ====================== --}}
@if($invoice->notes)
    <div class="notes-box">
        <h3>@gup($L('notes'))</h3>
        {!! nl2br(e($invoice->notes)) !!}
    </div>
@endif

{{-- ====================== Σχετικά παραστατικά ======================
     The customer-meaningful links (cancellation ↔ credit note(s), delivery
     notes) — mirrors the «Σχετικά παραστατικά» panel in Filament. Safe for the
     customer copy (no operator names / internal diffs). Printed only when there
     IS a relation. --}}
@php($relCredits = $invoice->creditNotes ?? collect())
@php($relDeliveries = $invoice->deliveryNotes ?? collect())
@php($showCreditedFor = $invoice->credited_invoice_id !== null && $invoice->creditedInvoice)
@if($invoice->isFullyCredited() || $showCreditedFor || $relCredits->isNotEmpty() || $relDeliveries->isNotEmpty())
    <div class="related">
        <h3>@gup($L('related_docs'))</h3>

        @if($invoice->isFullyCredited())
            {{-- PROV-019: only stamp «Ακυρώθηκε με πιστωτικό» on the printed document
                 when the reversal is LEGAL (isLegallyReversed). A still-draft credit
                 leaves the original standing at AADE, so the honest status is
                 «μειώθηκε με πρόχειρο πιστωτικό». --}}
            <div class="rel-row">
                <span class="rel-label">{{ $L('doc_status') }}:</span>
                <span class="rel-badge">{{ $invoice->isLegallyReversed() ? $L('cancelled_by_credit') : $L('reduced_by_draft_credit') }}</span>
            </div>
        @endif

        @if($showCreditedFor)
            <div class="rel-row">
                <span class="rel-label">{{ $L('credit_reverses') }}:</span>
                <strong>{{ $invoice->creditedInvoice->invcode }}</strong>
            </div>
            <div class="rel-note">{{ $L('credit_note_purpose') }}</div>
        @endif

        @if($relCredits->isNotEmpty())
            <div class="rel-row">
                {{-- Full cancel vs partial credit: «Ακυρώθηκε» only when the credit
                     notes fully AND legally reverse the invoice — else it would mislead
                     a customer who still owes a balance or whose reversal is a draft.
                     PROV-019: a full-but-draft credit reads as «μειώθηκε (πρόχειρο)». --}}
                <span class="rel-label">{{ ($invoice->isLegallyReversed() ? $L('cancelled_credited_with') : ($invoice->isFullyCredited() ? $L('reduced_credited_with') : $L('credited_partially_with'))).':' }}</span>
                <strong>{{ $relCredits->pluck('invcode')->implode(', ') }}</strong>
            </div>
            {{-- Only assert the AADE status when it's actually VALID — never on a
                 cancelled-at-AADE or non-myDATA invoice (would be a false claim). --}}
            @if($invoice->mydata_state === 'VALID')
                <div class="rel-note">{{ $L('original_valid_note') }}</div>
            @endif
        @endif

        @if($relDeliveries->isNotEmpty())
            <div class="rel-row">
                <span class="rel-label">{{ $L('delivery_notes') }}:</span>
                <strong>{{ $relDeliveries->pluck('invcode')->implode(', ') }}</strong>
            </div>
        @endif
    </div>
@endif

{{-- ====================== Footer (myDATA verification + per-tenant text + pagination) ====================== --}}
<div class="footer">
    {{-- DOC-7: the «Πιστοποιημένο στη myDATA — επαληθεύστε» claim only on a
         live VALID document — next to an ΑΚΥΡΩΘΕΝ banner it contradicts the
         page. (The QR + MARK stay in the header: scanning shows the real
         AADE state, cancelled included.) --}}
    @if($invoice->mydata_url && $invoice->mydata_state === 'VALID' && ! $isCancelledDoc)
        <div class="mydata-line">
            {{ $L('mydata_verify') }}
        </div>
        {{-- The AADE qrUrl is one long ~150-char token with no spaces. DomPDF
             won't break it in the fixed footer (it overflowed and got clipped on
             both sides → looked like a truncated/wrong URL). Insert zero-width
             break opportunities (U+200B) so it WRAPS across lines; invisible, so
             it still reads as the exact URL. The value itself is unchanged. --}}
        <div class="mydata-url">{{ implode("\u{200B}", mb_str_split((string) $invoice->mydata_url, 8)) }}</div>
    @endif
    @if(! empty($tenant->pdf_footer_text))
        <div class="tenant-text">{{ $tenant->pdf_footer_text }}</div>
    @endif
    <div style="margin-top:1mm">
        {{ $L('page') }} <span class="pager"></span> {{ $L('of') }} <span class="pager-total"></span>
    </div>
</div>

</body>
</html>
