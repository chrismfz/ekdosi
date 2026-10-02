<!doctype html>
@php($invoice = $documents[0]['invoice'])
<html lang="{{ $invoice->language ?? (strtoupper((string) $invoice->country) === 'GR' || blank($invoice->country) ? 'el' : 'en') }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ collect($documents)->map(fn ($d) => $d['invoice']->invcode)->implode(' + ') }}</title>
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
    .note { font-size: 9px; }
    .barcode { display: block; margin: 1mm auto; max-width: 100%; height: 12mm; }
    .cut { border-top: 2px dashed #000; margin: 6mm 0; page-break-after: always; }
    @media screen { body { background: #fff; box-shadow: 0 0 4px #aaa; padding: 4mm; margin: 4mm auto; } .noprint { margin: 3mm 0; text-align: center; } }
    @media print { .noprint { display: none; } }
</style>
</head>
<body>
    <div class="noprint"><button onclick="window.print()">Εκτύπωση</button></div>

    @foreach($documents as $i => $document)
        @if($i > 0)
            <div class="cut"></div>
        @endif
        @include('pos.receipt-document', $document)
    @endforeach

    <script>
        window.addEventListener('load', () => { window.focus(); window.print(); });
    </script>
</body>
</html>
