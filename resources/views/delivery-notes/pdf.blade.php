@php
    /** @var \App\Support\Pdf\PdfLabels $L */
    // Defensive default: the renderer (DeliveryNotePdf) always passes the frozen $L,
    // but the view is also rendered directly (tests, previews). Resolve the SAME
    // frozen language here from the note's snapshotted recipient country so a direct
    // view() call never breaks on an undefined $L and never diverges from the renderer.
    $L = $L ?? \App\Support\Pdf\PdfLabels::for(\App\Support\Pdf\PdfLabels::resolveLanguage(null, $note->recipientCountryIso()));
@endphp
<!DOCTYPE html>
<html lang="{{ $L->lang() === 'en' ? 'en' : 'el' }}">
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
        display:table-cell. Chrome labels localize via $L (PdfLabels); the myDATA
        code descriptions (σκοπός διακίνησης / τρόπος μεταφοράς / μονάδα μέτρησης)
        stay Greek — resolved via App\Support\MyData\DeliveryCodes, like the
        invoice's §8.3 legal citations.
    --}}
    <style>
        /* Compact single-page layout (PDF-COMPACT): mirrors the invoice PDF's
           tightened margins/fonts/spacing so a typical ΔΑ fits on one A4 page
           and reads denser (the roomy spacing wasted ~a third of the sheet). */
        @page { margin: 11mm 12mm 18mm 12mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 9pt; color: #1f2937; line-height: 1.22; }

        /* Banners */
        .banner { text-align: center; font-weight: bold; font-size: 12pt; padding: 2mm; margin-bottom: 2.5mm; border: 1.5pt solid; border-radius: 1.5mm; }
        .banner-draft     { color: #9a3412; border-color: #9a3412; background: #fff7ed; }
        .banner-cancelled { color: #7f1d1d; border-color: #7f1d1d; background: #fef2f2; }

        /* Header — tenant on the left, doc meta on the right */
        .hdr { display: table; width: 100%; table-layout: fixed; border-bottom: 1.2pt solid #111827; padding-bottom: 2mm; margin-bottom: 2.5mm; }
        .hdr-left  { display: table-cell; width: 60%; vertical-align: top; }
        .hdr-right { display: table-cell; width: 40%; vertical-align: top; text-align: right; }
        .hdr-logo  { max-height: 14mm; max-width: 48mm; margin-bottom: 1mm; }
        .tenant-name { font-size: 11.5pt; font-weight: bold; margin: 0 0 0.6mm 0; }
        .tenant-info { font-size: 8pt; color: #4b5563; }
        .doc-type    { font-size: 13pt; font-weight: bold; color: #111827; margin: 0; text-transform: uppercase; }
        .doc-code    { font-size: 11pt; color: #111827; margin: 0.6mm 0; }
        .doc-date    { font-size: 8.5pt; color: #4b5563; }
        .doc-mydata  { font-size: 8pt; color: #6b7280; }

        /* Two-column meta strip (issuer / recipient) */
        .meta { display: table; width: 100%; table-layout: fixed; margin-bottom: 2.5mm; }
        .meta-cell { display: table-cell; width: 50%; vertical-align: top; padding: 2mm 2.5mm; border: 1pt solid #d1d5db; border-radius: 1mm; }
        .meta-cell + .meta-cell { border-left: none; }
        .meta-cell h3 { font-size: 7.5pt; text-transform: uppercase; letter-spacing: 0.5pt; color: #6b7280; margin: 0 0 1mm 0; font-weight: bold; }
        .meta-cell .name { font-weight: bold; font-size: 9.5pt; margin-bottom: 0.6mm; }
        .meta-row { font-size: 8.5pt; color: #1f2937; }
        .meta-label { color: #6b7280; }

        /* Movement / transport block */
        .move { margin-bottom: 2.5mm; padding: 2mm 2.5mm; border: 1pt solid #d1d5db; border-radius: 1mm; }
        .move h3 { font-size: 7.5pt; text-transform: uppercase; letter-spacing: 0.5pt; color: #6b7280; margin: 0 0 1mm 0; font-weight: bold; }
        .move-grid { display: table; width: 100%; table-layout: fixed; }
        .move-col  { display: table-cell; width: 50%; vertical-align: top; }
        .move-row  { font-size: 8.5pt; color: #1f2937; padding: 0.2mm 0; }
        .move-label { color: #6b7280; }

        /* Lines table */
        .lines-wrap { page-break-inside: auto; }
        table.lines { width: 100%; border-collapse: collapse; }
        table.lines thead th { background: #f3f4f6; border-bottom: 1pt solid #9ca3af; padding: 1.4mm 2mm; font-size: 8pt; font-weight: bold; text-transform: uppercase; letter-spacing: 0.3pt; color: #374151; text-align: left; }
        table.lines tbody td { padding: 1.4mm 2mm; border-bottom: 0.5pt solid #e5e7eb; font-size: 8.5pt; vertical-align: top; }
        table.lines tbody tr { page-break-inside: avoid; }
        table.lines td.num, table.lines th.num { text-align: right; }
        table.lines td.center, table.lines th.center { text-align: center; }
        /* font-weight MUST be 400 or 700 — DomPDF can't match intermediate
           weights against DejaVu Sans and falls back to a Greek-less core
           font, rendering Greek as ?. (Same note as the invoice PDF.) */
        .line-desc { font-weight: normal; }
        .line-notes { font-size: 7.5pt; color: #6b7280; margin-top: 0.4mm; font-style: italic; }

        /* Notes */
        .notes-box { margin-top: 3mm; padding: 2mm 2.5mm; background: #f9fafb; border-left: 3pt solid #6b7280; font-size: 8.5pt; }
        .notes-box h3 { margin: 0 0 0.8mm 0; font-size: 8pt; text-transform: uppercase; color: #6b7280; letter-spacing: 0.3pt; }

        /* myDATA footer block: QR + MARK + url, only when filed */
        .mydata-foot { margin-top: 3mm; padding: 2mm 2.5mm; border: 1pt solid #e5e7eb; border-radius: 1mm; background: #fafafa; display: table; width: 100%; table-layout: fixed; page-break-inside: avoid; }
        .mydata-qr   { display: table-cell; width: 28mm; vertical-align: top; text-align: center; }
        .mydata-qr img { width: 24mm; height: 24mm; display: block; }
        .mydata-qr .qr-label { font-size: 6.5pt; color: #6b7280; margin: 0.6mm 0 0 0; }
        .mydata-info { display: table-cell; vertical-align: top; padding-left: 3mm; font-size: 8pt; color: #374151; }
        .mydata-info .mark { font-weight: bold; word-break: break-all; }
        .mydata-info .url  { word-break: break-all; overflow-wrap: anywhere; font-size: 6.5pt; color: #6b7280; margin-top: 0.8mm; }
        .draft-foot { margin-top: 3mm; padding: 2mm; text-align: center; border: 1pt dashed #9a3412; border-radius: 1mm; color: #9a3412; font-size: 8.5pt; font-weight: bold; }

        /* Ιστορικό (movement lifecycle + myDATA submissions), printed when present */
        .history { margin-top: 3mm; page-break-inside: auto; }
        .history h3 { font-size: 8pt; text-transform: uppercase; letter-spacing: 0.3pt; color: #6b7280; margin: 0 0 1mm 0; font-weight: bold; }
        .history .hist-sub { font-size: 7.5pt; font-weight: bold; color: #374151; margin: 1.5mm 0 0.8mm; }
        table.hist { width: 100%; border-collapse: collapse; }
        table.hist th { background: #f3f4f6; border-bottom: 1pt solid #cbd5e1; padding: 1mm 2mm; font-size: 7pt; text-align: left; color: #374151; font-weight: bold; }
        table.hist td { padding: 1mm 2mm; border-bottom: 0.5pt solid #eee; font-size: 7.5pt; vertical-align: top; color: #1f2937; }
        table.hist td.mono { word-break: break-all; }

        /* Page-bottom footer. The «Σελίδα X από Y» pager is drawn on the DomPDF
           canvas by DeliveryNotePdf::drawPager, because DomPDF 3.x resolves
           counter(pages) to 0 inside a fixed footer. */
        .footer { position: fixed; left: 0; right: 0; bottom: -9mm; text-align: center; font-size: 7pt; color: #6b7280; padding: 0 12mm; }
        .footer .tenant-text { font-style: italic; }
    </style>
</head>
<body>

@php
    use App\Support\MyData\DeliveryCodes;

    $mydataType   = $note->mydata_type ?? $note->deliveryType?->mydata_type;
    $isFiled      = $note->mydata_state === 'VALID' && ! empty($note->mydata_url);
    $isCancelled  = $note->mydata_state === 'CANCELLED';

    // Same fallback the submitter uses — a customer-linked note without the
    // recipient_name snapshot still files the customer's name, so the printed
    // δελτίο must not show an empty recipient block (MYD-011 review).
    $recipientName = trim((string) ($note->recipient_name ?: $note->customer?->name ?? ''));
    // Matching fallback for the ΑΦΜ, else a customer-linked note printed the
    // name with an empty «ΑΦΜ:» line while the submitter filed customer->afm.
    $recipientAfm  = trim((string) ($note->externalRecipientAfm() ?: \App\Models\DeliveryNote::INTERNAL_MOVEMENT_AFM));
    // ONE definition, shared with the AADE payload / CMR / provider document — the
    // old local heuristic called a named foreign recipient WITHOUT an ΑΦΜ an
    // ενδοδιακίνηση, so the printed δελτίο contradicted what was filed (MYD-011).
    $isInternal    = $note->isInternalMovement();
    $recipientIso  = $note->recipientCountryIso();

    $movePurposeLabel = DeliveryCodes::movePurposeLabel($note->move_purpose);
    if ((int) $note->move_purpose === 19 && ! empty($note->other_move_purpose_title)) {
        $movePurposeLabel = trim((string) $note->other_move_purpose_title);
    }
    $transportLabel = DeliveryCodes::transportTypeLabel($note->transport_type);
@endphp

{{-- ====================== State banner ====================== --}}
@if($isCancelled)
    <div class="banner banner-cancelled">{{ $L('banner_cancelled_dn') }}</div>
@elseif(! $isFiled)
    <div class="banner banner-draft">{{ $L('banner_draft_dn') }}</div>
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
                {{ $L('vat_no') }}: {{ $tenant->afm }}@if($tenant->tax_office) · {{ $L('tax_office') }} {{ $tenant->tax_office }}@endif
                <br>
            @endif
            {{-- DOC-4: ΓΕΜΗ (ν.4919/2022 αρ.22) + δραστηριότητα — ένα ΔΑ είναι κι αυτό εκδοθέν έγγραφο. --}}
            @if($tenant->gemi){{ $L('gemi') }}: {{ $tenant->gemi }}@if($tenant->kad_primary) · {{ $L('activity') }} {{ $tenant->kad_primary }}@endif<br>
            @elseif($tenant->kad_primary){{ $L('activity') }}: {{ $tenant->kad_primary }}<br>
            @endif
            @if($tenant->phone) {{ $L('phone') }}: {{ $tenant->phone }} @endif
            @if($tenant->email) · {{ $tenant->email }} @endif
        </p>
    </div>
    <div class="hdr-right">
        <p class="doc-type">@gup($L('delivery_note_title'))</p>
        <p class="doc-code">{{ $note->invcode }}</p>
        <p class="doc-date">{{ optional($note->issued_at)->format('d/m/Y H:i') }}</p>
        @if($mydataType)
            <p class="doc-mydata">{{ $L('dn_mydata_type') }}: {{ $mydataType }}</p>
        @endif
    </div>
</div>

{{-- ====================== Εκδότης / Παραλήπτης ====================== --}}
<div class="meta">
    <div class="meta-cell">
        <h3>@gup($L('issuer'))</h3>
        <div class="name">{{ $tenant->name ?? '—' }}</div>
        <div class="meta-row">
            @if($tenant->address) {{ $tenant->address }}<br> @endif
            @if($tenant->city || $tenant->postcode){{ $tenant->postcode }} {{ $tenant->city }}<br>@endif
            @if($tenant->afm){{ $L('vat_no') }}: {{ $tenant->afm }}@endif
        </div>
    </div>
    <div class="meta-cell">
        <h3>@gup($L('recipient'))</h3>
        @if($isInternal)
            <div class="name">{{ $L('internal_movement') }}</div>
            <div class="meta-row meta-label">{{ $L('internal_movement_desc') }}</div>
        @else
            <div class="name">{{ $recipientName }}</div>
            <div class="meta-row">{{ $L('vat_no') }}: {{ $recipientAfm }}</div>
            {{-- MYD-011: the frozen country is part of what was filed — show it for a
                 non-GR recipient so a wrong value is visible on the printed δελτίο. --}}
            @if($recipientIso !== null && $recipientIso !== 'GR')
                <div class="meta-row">{{ $L('country') }}: {{ $recipientIso }}</div>
            @endif
        @endif
    </div>
</div>

{{-- ====================== Στοιχεία διακίνησης ====================== --}}
<div class="move">
    <h3>@gup($L('movement_details'))</h3>
    <div class="move-grid">
        <div class="move-col">
            @if($movePurposeLabel)
                <div class="move-row"><span class="move-label">{{ $L('movement_purpose') }}:</span> {{ $movePurposeLabel }}</div>
            @endif
            @if($note->loading_street || $note->loading_city || $note->loading_postcode)
                <div class="move-row"><span class="move-label">{{ $L('loading_place') }}:</span>
                    {{ trim(($note->loading_street ?? '').' '.($note->loading_number ?? '')) }}{{ ($note->loading_street || $note->loading_number) && ($note->loading_postcode || $note->loading_city) ? ', ' : '' }}{{ trim(($note->loading_postcode ?? '').' '.($note->loading_city ?? '')) }}
                </div>
            @endif
            @if($note->delivery_street || $note->delivery_city || $note->delivery_postcode)
                <div class="move-row"><span class="move-label">{{ $L('delivery_place') }}:</span>
                    {{ trim(($note->delivery_street ?? '').' '.($note->delivery_number ?? '')) }}{{ ($note->delivery_street || $note->delivery_number) && ($note->delivery_postcode || $note->delivery_city) ? ', ' : '' }}{{ trim(($note->delivery_postcode ?? '').' '.($note->delivery_city ?? '')) }}
                </div>
            @endif
        </div>
        <div class="move-col">
            @if($transportLabel)
                <div class="move-row"><span class="move-label">{{ $L('transport_means') }}:</span> {{ $transportLabel }}</div>
            @endif
            @if($note->vehicle_number)
                <div class="move-row"><span class="move-label">{{ $L('vehicle') }}:</span> {{ $note->vehicle_number }}</div>
            @endif
            @if($note->carrier_afm)
                <div class="move-row"><span class="move-label">{{ $L('carrier_vat') }}:</span> {{ $note->carrier_afm }}</div>
            @endif
            @if($note->dispatch_at)
                <div class="move-row"><span class="move-label">{{ $L('dispatch_time') }}:</span> {{ optional($note->dispatch_at)->format('d/m/Y H:i') }}</div>
            @endif
        </div>
    </div>
</div>

{{-- ====================== Είδη (γραμμές) ====================== --}}
<div class="lines-wrap">
    <table class="lines">
        <thead>
            <tr>
                <th class="center" style="width: 8%">@gup($L('line_no'))</th>
                <th style="width: 62%">@gup($L('item'))</th>
                <th class="num" style="width: 15%">@gup($L('quantity'))</th>
                <th class="center" style="width: 15%">@gup($L('unit_full'))</th>
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
                <tr><td colspan="4" style="text-align:center; color:#9ca3af; font-style:italic">— {{ $L('no_lines') }} —</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

{{-- ====================== Παρατηρήσεις ====================== --}}
@if($note->notes)
    <div class="notes-box">
        <h3>@gup($L('notes'))</h3>
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
            <div>{{ $L('mydata_submitted_verify') }}</div>
            @if($note->mydata_mark)
                <div class="mark">{{ $L('mark_label') }}: {{ $note->mydata_mark }}</div>
            @endif
            {{-- The verification URL is one long token with no spaces (AADE qrUrl or
                 the provider's viewinvoice.php?…). DomPDF won't break it and it
                 overflowed/clipped at the page edge — inject a zero-width space every
                 8 chars so it wraps. Same fix as the invoice PDF footer. --}}
            <div class="url">{{ implode("\u{200B}", mb_str_split((string) $note->mydata_url, 8)) }}</div>
        </div>
    </div>
@else
    <div class="draft-foot">{{ $L('draft_no_mydata') }}</div>
@endif

{{-- ====================== Ιστορικό (διακίνηση + υποβολές myDATA) ====================== --}}
@php($histEvents = $events ?? collect())
@php($histMarks = $marks ?? collect())
@if($histEvents->isNotEmpty() || $histMarks->isNotEmpty())
    <div class="history">
        <h3>@gup($L('history'))</h3>

        @if($histEvents->isNotEmpty())
            <div class="hist-sub">{{ $L('movement') }}</div>
            <table class="hist">
                <thead>
                    <tr>
                        <th style="width:24%">{{ $L('date') }}</th>
                        <th style="width:26%">{{ $L('event') }}</th>
                        <th style="width:34%">{{ $L('details') }}</th>
                        <th style="width:16%">{{ $L('from_actor') }}</th>
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
            <div class="hist-sub">{{ $L('mydata_submissions') }}</div>
            <table class="hist">
                <thead>
                    <tr>
                        <th style="width:24%">{{ $L('date') }}</th>
                        <th style="width:26%">{{ $L('action') }}</th>
                        <th style="width:50%">{{ $L('mark_label') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($histMarks as $m)
                        <tr>
                            <td>{{ optional($m->created_at)->format('d/m/Y H:i') }}</td>
                            <td>{{ $m->actionLabel() }}</td>
                            {{-- MYD-023: a CANCEL row now carries the cancelled document's
                                 MARK in `mark` and AADE's own cancellation MARK in its own
                                 column. Show both so the printed audit still proves WHICH
                                 cancellation produced the terminal state — the provider path
                                 used to display the cancellation MARK here instead. --}}
                            <td class="mono">{{ $m->mark ?: '—' }}@if($m->cancellation_mark)<br>{{ $L('cancellation') }}: {{ $m->cancellation_mark }}@endif</td>
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
</div>
{{-- The «Σελίδα X από Y» pager is drawn on the DomPDF canvas by
     DeliveryNotePdf::drawPager (counter(pages) resolves to 0 inside this fixed
     footer on DomPDF 3.x). --}}

</body>
</html>
