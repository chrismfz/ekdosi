@php
    /** @var \App\Models\CmrNote $cmr */
    $nl2br = fn (?string $s) => nl2br(e((string) $s));
    $money = fn ($v) => $v === null ? '' : number_format((float) $v, 2);
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>CMR {{ $cmr->code() }}</title>
    {{--
        CMR international consignment note — faithful single-A4 reproduction of
        the standard 24-box form (docs/reference/cmr-template.pdf). DomPDF subset
        of CSS only (no flex/grid): table + display:table-cell layouts. English
        labels. NOT a myDATA document — no QR/MARK.
    --}}
    <style>
        @page { margin: 8mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 7.5pt; color: #111827; line-height: 1.25; }
        .title { text-align: center; font-weight: bold; font-size: 11pt; margin: 0 0 1mm 0; }
        .subtitle { text-align: center; font-size: 6.5pt; color: #374151; margin: 0 0 2mm 0; }
        table.grid { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .grid td { border: 0.6pt solid #111827; vertical-align: top; padding: 1mm; }
        .bx { font-size: 6pt; color: #6b7280; font-weight: bold; text-transform: uppercase; display: block; margin-bottom: 0.5mm; }
        .val { font-size: 7.5pt; }
        .goods th { border: 0.6pt solid #111827; background: #f3f4f6; font-size: 6pt; padding: 0.8mm; text-transform: uppercase; }
        .goods td { border: 0.6pt solid #111827; font-size: 7pt; padding: 0.8mm; vertical-align: top; }
        .sig { height: 16mm; }
        .ref { text-align: right; font-size: 8pt; font-weight: bold; }
        .copy { font-size: 6pt; color: #6b7280; }
        .charges td { border: 0.4pt solid #9ca3af; font-size: 6.5pt; padding: 0.6mm; }
    </style>
</head>
<body>
    <table class="grid" style="margin-bottom:1mm; border:none;">
        <tr style="border:none;">
            <td style="border:none; width:60%;">
                <span class="copy">{{ $cmr->status === \App\Models\CmrNote::STATUS_DRAFT ? 'DRAFT — not final' : 'Copy 1 — Sender' }}</span>
            </td>
            <td style="border:none; width:40%;" class="ref">Reference No. {{ $cmr->code() }}</td>
        </tr>
    </table>

    <p class="title">CMR — INTERNATIONAL CONSIGNMENT NOTE</p>
    <p class="subtitle">This carriage is subject, notwithstanding any clause to the contrary, to the Convention on the Contract for the International Carriage of Goods by Road (CMR).</p>

    <table class="grid">
        <tr>
            <td style="width:50%;"><span class="bx">1 — Sender (name, address, country)</span><span class="val">{!! $nl2br($cmr->sender_text) !!}</span></td>
            <td style="width:50%;"><span class="bx">16 — Carrier (name, address, country)</span><span class="val">{!! $nl2br(trim($cmr->carrier_name."\n".$cmr->carrier_address)) !!}</span></td>
        </tr>
        <tr>
            <td><span class="bx">2 — Consignee (name, address, country)</span><span class="val">{!! $nl2br($cmr->consignee_text) !!}</span></td>
            <td><span class="bx">17 — Successive carriers</span><span class="val">{!! $nl2br($cmr->successive_carrier) !!}</span></td>
        </tr>
        <tr>
            <td><span class="bx">3 — Place of delivery (place, country)</span><span class="val">{!! $nl2br($cmr->delivery_text) !!}</span></td>
            <td><span class="bx">18 — Carrier's reservations &amp; observations</span><span class="val">{!! $nl2br($cmr->carrier_reservations) !!}</span></td>
        </tr>
        <tr>
            <td><span class="bx">4 — Place &amp; date of taking over the goods</span><span class="val">{{ $cmr->taking_over_place }} @if($cmr->taking_over_at) — {{ $cmr->taking_over_at->format('d/m/Y') }} @endif</span></td>
            <td><span class="bx">5 — Annexed documents</span><span class="val">{!! $nl2br($cmr->annexed_documents) !!}</span></td>
        </tr>
    </table>

    <table class="goods" style="width:100%; border-collapse:collapse; margin-top:1mm;">
        <thead>
            <tr>
                <th style="width:14%;">6 — Marks &amp; numbers</th>
                <th style="width:9%;">7 — No. of packages</th>
                <th style="width:13%;">8 — Method of packing</th>
                <th>9 — Nature of the goods</th>
                <th style="width:11%;">10 — Statistical no.</th>
                <th style="width:11%;">11 — Gross weight (kg)</th>
                <th style="width:9%;">12 — Volume (m³)</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($cmr->lines as $line)
                <tr>
                    <td>{{ $line->marks_numbers }}</td>
                    <td>{{ $line->packages_count }}</td>
                    <td>{{ $line->packing_method }}</td>
                    <td>{{ $line->nature_en }}@if($line->adr_class) <em>(ADR {{ $line->adr_class }})</em>@endif</td>
                    <td>{{ $line->statistical_no }}</td>
                    <td>{{ $line->weight_kg !== null ? rtrim(rtrim((string) $line->weight_kg, '0'), '.') : '' }}</td>
                    <td>{{ $line->volume_m3 !== null ? rtrim(rtrim((string) $line->volume_m3, '0'), '.') : '' }}</td>
                </tr>
            @empty
                <tr><td colspan="7" style="height:14mm;"></td></tr>
            @endforelse
        </tbody>
    </table>

    <table class="grid" style="margin-top:1mm;">
        <tr>
            <td style="width:50%;"><span class="bx">13 — Sender's instructions (customs &amp; other formalities)</span><span class="val">{!! $nl2br($cmr->sender_instructions) !!}</span></td>
            <td style="width:50%;"><span class="bx">19 — Special agreements</span><span class="val">{!! $nl2br($cmr->special_agreements) !!}</span></td>
        </tr>
        <tr>
            <td>
                <span class="bx">14 — Directions as to freight payment</span>
                <span class="val">{{ $cmr->freight_paid === null ? '' : ($cmr->freight_paid ? 'Freight paid' : 'Freight to be paid') }}</span>
                <br><span class="bx" style="margin-top:1mm;">15 — Cash on delivery</span><span class="val">{{ $money($cmr->cash_on_delivery) }}</span>
            </td>
            <td>
                <span class="bx">20 — To be paid by — {{ $cmr->charges_to_be_paid_by ? ucfirst($cmr->charges_to_be_paid_by) : '' }}</span>
                <table style="width:100%; border-collapse:collapse;" class="charges">
                    <tr><td>Carriage charges</td><td style="text-align:right;">{{ $money($cmr->carriage_charges) }}</td></tr>
                    <tr><td>Reductions</td><td style="text-align:right;">{{ $money($cmr->reductions) }}</td></tr>
                    <tr><td>Balance</td><td style="text-align:right;">{{ $money($cmr->balance) }}</td></tr>
                    <tr><td>Supplement</td><td style="text-align:right;">{{ $money($cmr->supplement) }}</td></tr>
                    <tr><td>Miscellaneous</td><td style="text-align:right;">{{ $money($cmr->misc_charges) }}</td></tr>
                    <tr><td><strong>Total to be paid</strong></td><td style="text-align:right;"><strong>{{ $money($cmr->total_charges) }}</strong></td></tr>
                </table>
            </td>
        </tr>
        <tr>
            <td colspan="2"><span class="bx">21 — Established in / on</span><span class="val">{{ $cmr->established_place }} @if($cmr->established_on) — {{ $cmr->established_on->format('d/m/Y') }} @endif</span></td>
        </tr>
        <tr>
            <td class="sig"><span class="bx">22 — Signature &amp; stamp of the sender</span></td>
            <td class="sig">
                <span class="bx">23 — Signature &amp; stamp of the carrier</span>
                <span class="copy">Tractor: {{ $cmr->tractor_plate }} &nbsp; Trailer: {{ $cmr->trailer_plate }}</span>
            </td>
        </tr>
        <tr>
            <td colspan="2" class="sig"><span class="bx">24 — Signature &amp; stamp of the consignee</span></td>
        </tr>
    </table>
</body>
</html>
