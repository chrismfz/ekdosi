<!DOCTYPE html>
<html lang="el">
<head>
    <meta charset="UTF-8">
    <title>{{ $note->invcode }}</title>
    {{--
        Δελτίο Αποστολής (myDATA Παραστατικό Διακίνησης, 9.x) — value-LESS:
        quantities only, no prices / VAT / totals. Mirrors the invoice PDF's
        font + encoding setup (DejaVu Sans, font-weight strictly 400/700 so
        DomPDF doesn't fall back to a non-Greek core font), A4 margins and the
        QR/MARK footer block. The QR + MARK render ONLY when filed (VALID); a
        draft shows the «ΠΡΟΧΕΙΡΟ» banner and no QR.

        DomPDF subset of CSS only — no flexbox/grid/calc; table layouts +
        display:table-cell. Greek labels resolved in the controller/Blade via
        App\Support\MyData\DeliveryCodes.
    --}}
    <style>
        @page { margin: 16mm 14mm 22mm 14mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 9.5pt; color: #1f2937; line-height: 1.35; }

        /* Banners */
        .banner { text-align: center; font-weight: bold; font-size: 14pt; padding: 3mm; margin-bottom: 4mm; border: 2px solid; border-radius: 2mm; }
        .banner-draft     { color: #9a3412; border-color: #9a3412; background: #fff7ed; }
        .banner-cancelled { color: #7f1d1d; border-color: #7f1d1d; background: #fef2f2; }

        /* Header — tenant on the left, doc meta on the right */
        .hdr { display: table; width: 100%; table-layout: fixed; border-bottom: 1.5pt solid #111827; padding-bottom: 3mm; margin-bottom: 4mm; }
        .hdr-left  { display: table-cell; width: 60%; vertical-align: top; }
        .hdr-right { display: table-cell; width: 40%; vertical-align: top; text-align: right; }
        .hdr-logo  { max-height: 22mm; max-width: 60mm; margin-bottom: 2mm; }
        .tenant-name { font-size: 13pt; font-weight: bold; margin: 0 0 1mm 0; }
        .tenant-info { font-size: 8.5pt; color: #4b5563; }
        .doc-type    { font-size: 14pt; font-weight: bold; color: #111827; margin: 0; text-transform: uppercase; }
        .doc-code    { font-size: 12pt; color: #111827; margin: 1mm 0; }
        .doc-date    { font-size: 9pt; color: #4b5563; }
        .doc-mydata  { font-size: 8.5pt; color: #6b7280; }

        /* Two-column meta strip (issuer / recipient) */
        .meta { display: table; width: 100%; table-layout: fixed; margin-bottom: 4mm; }
        .meta-cell { display: table-cell; width: 50%; vertical-align: top; padding: 3mm; border: 1pt solid #d1d5db; border-radius: 1mm; }
        .meta-cell + .meta-cell { border-left: none; }
        .meta-cell h3 { font-size: 8pt; text-transform: uppercase; letter-spacing: 0.5pt; color: #6b7280; margin: 0 0 1.5mm 0; font-weight: bold; }
        .meta-cell .name { font-weight: bold; font-size: 10.5pt; margin-bottom: 1mm; }
        .meta-row { font-size: 9pt; color: #1f2937; }
        .meta-label { color: #6b7280; }

        /* Movement / transport block */
        .move { margin-bottom: 4mm; padding: 3mm; border: 1pt solid #d1d5db; border-radius: 1mm; }
        .move h3 { font-size: 8pt; text-transform: uppercase; letter-spacing: 0.5pt; color: #6b7280; margin: 0 0 1.5mm 0; font-weight: bold; }
        .move-grid { display: table; width: 100%; table-layout: fixed; }
        .move-col  { display: table-cell; width: 50%; vertical-align: top; }
        .move-row  { font-size: 9pt; color: #1f2937; padding: 0.3mm 0; }
        .move-label { color: #6b7280; }

        /* Lines table */
        .lines-wrap { page-break-inside: auto; }
        table.lines { width: 100%; border-collapse: collapse; }
        table.lines thead th { background: #f3f4f6; border-bottom: 1pt solid #9ca3af; padding: 2mm; font-size: 8.5pt; font-weight: bold; text-transform: uppercase; letter-spacing: 0.3pt; color: #374151; text-align: left; }
        table.lines tbody td { padding: 2mm; border-bottom: 0.5pt solid #e5e7eb; font-size: 9pt; vertical-align: top; }
        table.lines tbody tr { page-break-inside: avoid; }
        table.lines td.num, table.lines th.num { text-align: right; }
        table.lines td.center, table.lines th.center { text-align: center; }
        /* font-weight MUST be 400 or 700 — DomPDF can't match intermediate
           weights against DejaVu Sans and falls back to a Greek-less core
           font, rendering Greek as ?. (Same note as the invoice PDF.) */
        .line-desc { font-weight: normal; }
        .line-notes { font-size: 8pt; color: #6b7280; margin-top: 0.5mm; font-style: italic; }

        /* Notes */
        .notes-box { margin-top: 5mm; padding: 3mm; background: #f9fafb; border-left: 3pt solid #6b7280; font-size: 9pt; }
        .notes-box h3 { margin: 0 0 1mm 0; font-size: 8.5pt; text-transform: uppercase; color: #6b7280; letter-spacing: 0.3pt; }

        /* myDATA footer block: QR + MARK + url, only when filed */
        .mydata-foot { margin-top: 6mm; padding: 3mm; border: 1pt solid #e5e7eb; border-radius: 1mm; background: #fafafa; display: table; width: 100%; table-layout: fixed; }
        .mydata-qr   { display: table-cell; width: 32mm; vertical-align: top; text-align: center; }
        .mydata-qr img { width: 28mm; height: 28mm; display: block; }
        .mydata-qr .qr-label { font-size: 7pt; color: #6b7280; margin: 1mm 0 0 0; }
        .mydata-info { display: table-cell; vertical-align: top; padding-left: 3mm; font-size: 8.5pt; color: #374151; }
        .mydata-info .mark { font-weight: bold; word-break: break-all; }
        .mydata-info .url  { word-break: break-all; overflow-wrap: anywhere; font-size: 7.5pt; color: #6b7280; margin-top: 1mm; }
        .draft-foot { margin-top: 6mm; padding: 3mm; text-align: center; border: 1pt dashed #9a3412; border-radius: 1mm; color: #9a3412; font-size: 9pt; font-weight: bold; }

        /* Ιστορικό (movement lifecycle + myDATA submissions), printed when present */
        .history { margin-top: 6mm; page-break-inside: auto; }
        .history h3 { font-size: 8.5pt; text-transform: uppercase; letter-spacing: 0.3pt; color: #6b7280; margin: 0 0 1.5mm 0; font-weight: bold; }
        .history .hist-sub { font-size: 8pt; font-weight: bold; color: #374151; margin: 2mm 0 1mm; }
        table.hist { width: 100%; border-collapse: collapse; }
        table.hist th { background: #f3f4f6; border-bottom: 1pt solid #cbd5e1; padding: 1.2mm 2mm; font-size: 7.5pt; text-align: left; color: #374151; font-weight: bold; }
        table.hist td { padding: 1.2mm 2mm; border-bottom: 0.5pt solid #eee; font-size: 8pt; vertical-align: top; color: #1f2937; }
        table.hist td.mono { word-break: break-all; }

        /* Page-bottom footer */
        .footer { position: fixed; left: 0; right: 0; bottom: -16mm; text-align: center; font-size: 7.5pt; color: #6b7280; padding: 0 14mm; }
        .footer .tenant-text { font-style: italic; }
        .pager:after { content: counter(page); }
        .pager-total:after { content: counter(pages); }
    </style>
</head>
<body>

@php
    use App\Support\MyData\DeliveryCodes;

    $mydataType   = $note->mydata_type ?? $note->deliveryType?->mydata_type;
    $isFiled      = $note->mydata_state === 'VALID' && ! empty($note->mydata_url);
    $isCancelled  = $note->mydata_state === 'CANCELLED';

    $recipientName = trim((string) ($note->recipient_name ?? ''));
    $recipientAfm  = trim((string) ($note->recipient_afm ?? ''));
    $isInternal    = $recipientName === '' || $recipientAfm === '' || $recipientAfm === '000000000';

    $movePurposeLabel = DeliveryCodes::movePurposeLabel($note->move_purpose);
    if ((int) $note->move_purpose === 19 && ! empty($note->other_move_purpose_title)) {
        $movePurposeLabel = trim((string) $note->other_move_purpose_title);
    }
    $transportLabel = DeliveryCodes::transportTypeLabel($note->transport_type);
@endphp

{{-- ====================== State banner ====================== --}}
@if($isCancelled)
    <div class="banner banner-cancelled">ΑΚΥΡΩΘΕΝ ΔΕΛΤΙΟ — Δεν έχει νόμιμη ισχύ</div>
@elseif(! $isFiled)
    <div class="banner banner-draft">ΠΡΟΧΕΙΡΟ — ΜΗ ΔΙΑΒΙΒΑΣΜΕΝΟ ΣΤΗ myDATA</div>
@endif

{{-- ====================== Header ====================== --}}
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
                ΑΦΜ: {{ $tenant->afm }}@if($tenant->tax_office) · ΔΟΥ {{ $tenant->tax_office }}@endif
                <br>
            @endif
            @if($tenant->phone) Τηλ: {{ $tenant->phone }} @endif
            @if($tenant->email) · {{ $tenant->email }} @endif
        </p>
    </div>
    <div class="hdr-right">
        <p class="doc-type">@gup('Δελτίο Αποστολής')</p>
        <p class="doc-code">{{ $note->invcode }}</p>
        <p class="doc-date">{{ optional($note->issued_at)->format('d/m/Y H:i') }}</p>
        @if($mydataType)
            <p class="doc-mydata">Τύπος myDATA: {{ $mydataType }}</p>
        @endif
    </div>
</div>

{{-- ====================== Εκδότης / Παραλήπτης ====================== --}}
<div class="meta">
    <div class="meta-cell">
        <h3>@gup('Εκδότης')</h3>
        <div class="name">{{ $tenant->name ?? '—' }}</div>
        <div class="meta-row">
            @if($tenant->address) {{ $tenant->address }}<br> @endif
            @if($tenant->city || $tenant->postcode){{ $tenant->postcode }} {{ $tenant->city }}<br>@endif
            @if($tenant->afm)ΑΦΜ: {{ $tenant->afm }}@endif
        </div>
    </div>
    <div class="meta-cell">
        <h3>@gup('Παραλήπτης')</h3>
        @if($isInternal)
            <div class="name">Ενδοδιακίνηση</div>
            <div class="meta-row meta-label">Διακίνηση εντός της επιχείρησης</div>
        @else
            <div class="name">{{ $recipientName }}</div>
            <div class="meta-row">ΑΦΜ: {{ $recipientAfm }}</div>
        @endif
    </div>
</div>

{{-- ====================== Στοιχεία διακίνησης ====================== --}}
<div class="move">
    <h3>@gup('Στοιχεία Διακίνησης')</h3>
    <div class="move-grid">
        <div class="move-col">
            @if($movePurposeLabel)
                <div class="move-row"><span class="move-label">Σκοπός διακίνησης:</span> {{ $movePurposeLabel }}</div>
            @endif
            @if($note->loading_street || $note->loading_city || $note->loading_postcode)
                <div class="move-row"><span class="move-label">Τόπος φόρτωσης:</span>
                    {{ trim(($note->loading_street ?? '').' '.($note->loading_number ?? '')) }}{{ ($note->loading_street || $note->loading_number) && ($note->loading_postcode || $note->loading_city) ? ', ' : '' }}{{ trim(($note->loading_postcode ?? '').' '.($note->loading_city ?? '')) }}
                </div>
            @endif
            @if($note->delivery_street || $note->delivery_city || $note->delivery_postcode)
                <div class="move-row"><span class="move-label">Τόπος παράδοσης:</span>
                    {{ trim(($note->delivery_street ?? '').' '.($note->delivery_number ?? '')) }}{{ ($note->delivery_street || $note->delivery_number) && ($note->delivery_postcode || $note->delivery_city) ? ', ' : '' }}{{ trim(($note->delivery_postcode ?? '').' '.($note->delivery_city ?? '')) }}
                </div>
            @endif
        </div>
        <div class="move-col">
            @if($transportLabel)
                <div class="move-row"><span class="move-label">Μεταφορικό μέσο:</span> {{ $transportLabel }}</div>
            @endif
            @if($note->vehicle_number)
                <div class="move-row"><span class="move-label">Όχημα:</span> {{ $note->vehicle_number }}</div>
            @endif
            @if($note->carrier_afm)
                <div class="move-row"><span class="move-label">Μεταφορέας (ΑΦΜ):</span> {{ $note->carrier_afm }}</div>
            @endif
            @if($note->dispatch_at)
                <div class="move-row"><span class="move-label">Ημ/ώρα έναρξης:</span> {{ optional($note->dispatch_at)->format('d/m/Y H:i') }}</div>
            @endif
        </div>
    </div>
</div>

{{-- ====================== Είδη (γραμμές) ====================== --}}
<div class="lines-wrap">
    <table class="lines">
        <thead>
            <tr>
                <th class="center" style="width: 8%">@gup('Α/Α')</th>
                <th style="width: 62%">@gup('Είδος')</th>
                <th class="num" style="width: 15%">@gup('Ποσότητα')</th>
                <th class="center" style="width: 15%">@gup('Μονάδα')</th>
            </tr>
        </thead>
        <tbody>
            @forelse($note->lines as $i => $line)
                @php
                    $descr   = $line->product_descr ?: ($line->product?->name ?? '—');
                    $unitInt = $line->measurement_unit !== null ? (int) $line->measurement_unit : null;
                    $unitLbl = DeliveryCodes::measurementUnitLabel($unitInt)
                        ?? ($line->metric_unit ?: ($unitInt !== null ? (string) $unitInt : '—'));
                @endphp
                <tr>
                    <td class="center">{{ $i + 1 }}</td>
                    <td>
                        <div class="line-desc">{{ $descr }}</div>
                        @if($line->notes)<div class="line-notes">{{ $line->notes }}</div>@endif
                    </td>
                    <td class="num">{{ number_format((float) $line->qty, 3, ',', '.') }}</td>
                    <td class="center">{{ $unitLbl }}</td>
                </tr>
            @empty
                <tr><td colspan="4" style="text-align:center; color:#9ca3af; font-style:italic">— Καμία γραμμή —</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

{{-- ====================== Παρατηρήσεις ====================== --}}
@if($note->notes)
    <div class="notes-box">
        <h3>@gup('Παρατηρήσεις')</h3>
        {!! nl2br(e($note->notes)) !!}
    </div>
@endif

{{-- ====================== myDATA: QR + MARK (only when filed) ====================== --}}
@if($isFiled)
    <div class="mydata-foot">
        <div class="mydata-qr">
            @if($qrDataUri)
                <img src="{{ $qrDataUri }}" alt="myDATA QR">
                <p class="qr-label">myDATA</p>
            @endif
        </div>
        <div class="mydata-info">
            <div>Διαβιβάστηκε στη myDATA — επαληθεύστε σαρώνοντας το QR ή στη διεύθυνση:</div>
            @if($note->mydata_mark)
                <div class="mark">ΜΑΡΚ: {{ $note->mydata_mark }}</div>
            @endif
            {{-- The verification URL is one long token with no spaces (AADE qrUrl or
                 the provider's viewinvoice.php?…). DomPDF won't break it and it
                 overflowed/clipped at the page edge — inject a zero-width space every
                 8 chars so it wraps. Same fix as the invoice PDF footer. --}}
            <div class="url">{{ implode("\u{200B}", mb_str_split((string) $note->mydata_url, 8)) }}</div>
        </div>
    </div>
@else
    <div class="draft-foot">ΠΡΟΧΕΙΡΟ — δεν έχει διαβιβαστεί στη myDATA (χωρίς ΜΑΡΚ/QR)</div>
@endif

{{-- ====================== Ιστορικό (διακίνηση + υποβολές myDATA) ====================== --}}
@php($histEvents = $events ?? collect())
@php($histMarks = $marks ?? collect())
@if($histEvents->isNotEmpty() || $histMarks->isNotEmpty())
    <div class="history">
        <h3>@gup('Ιστορικό')</h3>

        @if($histEvents->isNotEmpty())
            <div class="hist-sub">Διακίνηση</div>
            <table class="hist">
                <thead>
                    <tr>
                        <th style="width:24%">Ημ/νία</th>
                        <th style="width:26%">Γεγονός</th>
                        <th style="width:34%">Λεπτομέρειες</th>
                        <th style="width:16%">Από</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($histEvents as $e)
                        <tr>
                            <td>{{ optional($e->event_timestamp)->format('d/m/Y H:i') }}</td>
                            <td>{{ $e->typeLabel() }}</td>
                            <td>{{ $e->summary() }}</td>
                            <td>{{ $e->actor_vat ?: '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        @if($histMarks->isNotEmpty())
            <div class="hist-sub">Υποβολές myDATA</div>
            <table class="hist">
                <thead>
                    <tr>
                        <th style="width:24%">Ημ/νία</th>
                        <th style="width:26%">Ενέργεια</th>
                        <th style="width:50%">ΜΑΡΚ</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($histMarks as $m)
                        <tr>
                            <td>{{ optional($m->created_at)->format('d/m/Y H:i') }}</td>
                            <td>{{ $m->actionLabel() }}</td>
                            <td class="mono">{{ $m->mark ?: '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
@endif

{{-- ====================== Page footer ====================== --}}
<div class="footer">
    @if(! empty($tenant->pdf_footer_text))
        <div class="tenant-text">{{ $tenant->pdf_footer_text }}</div>
    @endif
    <div style="margin-top:1mm">
        Σελίδα <span class="pager"></span> από <span class="pager-total"></span>
    </div>
</div>

</body>
</html>
