<!doctype html>
@php($money = fn ($v) => number_format((float) $v, 2, ',', '.'))
@php($company = $session->company)
<html lang="el">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Ταμείο #{{ $session->id }}</title>
<style>
    @page { size: 80mm auto; margin: 0; }
    * { box-sizing: border-box; }
    body { width: 72mm; margin: 0 auto; padding: 3mm 0; font: 11px/1.35 "DejaVu Sans", Arial, sans-serif; color: #000; }
    .c { text-align: center; }
    .r { text-align: right; }
    .b { font-weight: 700; }
    .big { font-size: 14px; }
    .small { font-size: 9px; }
    hr { border: 0; border-top: 1px dashed #000; margin: 2mm 0; }
    table { width: 100%; border-collapse: collapse; }
    td { vertical-align: top; padding: .3mm 0; }
    @media screen { body { background: #fff; box-shadow: 0 0 4px #aaa; padding: 4mm; margin: 4mm auto; } .noprint { margin: 3mm 0; text-align: center; } }
    @media print { .noprint { display: none; } }
</style>
</head>
<body>
    <div class="noprint"><button onclick="window.print()">Εκτύπωση</button></div>

    <div class="c b">{{ $company?->name }}</div>
    <div class="c b big">{{ $session->isOpen() ? 'ΕΝΔΙΑΜΕΣΗ ΑΝΑΦΟΡΑ ΤΑΜΕΙΟΥ' : 'ΚΛΕΙΣΙΜΟ ΤΑΜΕΙΟΥ' }}</div>
    <div class="c small">Εσωτερική αναφορά — δεν αποτελεί φορολογικό στοιχείο</div>
    <hr>
    <table>
        <tr><td>Ταμείο</td><td class="r">#{{ $session->id }}</td></tr>
        <tr><td>Άνοιγμα</td><td class="r">{{ $session->opened_at?->format('d/m/Y H:i') }} · {{ $session->opener?->name ?? '—' }}</td></tr>
        @if (! $session->isOpen())
            <tr><td>Κλείσιμο</td><td class="r">{{ $session->closed_at?->format('d/m/Y H:i') }} · {{ $session->closer?->name ?? '—' }}</td></tr>
        @else
            <tr><td>Εκτύπωση</td><td class="r">{{ now()->format('d/m/Y H:i') }}</td></tr>
        @endif
    </table>
    <hr>
    <table>
        <tr><td>Πωλήσεις ({{ $report['sales_count'] }})</td><td class="r">{{ $money($report['sales_total']) }} €</td></tr>
        <tr><td>Επιστροφές ({{ $report['refunds_count'] }})</td><td class="r">−{{ $money($report['refunds_total']) }} €</td></tr>
        <tr class="b"><td>Καθαρός τζίρος</td><td class="r">{{ $money($report['net_total']) }} €</td></tr>
        @if (($report['levies'] ?? 0) != 0)
            <tr class="small"><td>&nbsp;&nbsp;εκ των οποίων τέλη (π.χ. σακούλα)</td><td class="r">{{ $money($report['levies']) }} €</td></tr>
        @endif
    </table>

    @if ($report['by_method'] !== [])
        <hr>
        <div class="b">Ανά τρόπο πληρωμής</div>
        <table>
            @foreach ($report['by_method'] as $m)
                <tr><td>{{ $m['method'] }}</td><td class="r">{{ $money($m['sales'] - $m['refunds']) }} €</td></tr>
                <tr class="small"><td colspan="2">&nbsp;&nbsp;{{ $m['sales_count'] }} πωλ. {{ $money($m['sales']) }} · {{ $m['refunds_count'] }} επιστρ. −{{ $money($m['refunds']) }}</td></tr>
            @endforeach
        </table>
    @endif

    @if ($report['vat'] !== [])
        <hr>
        <div class="b">ΦΠΑ (πωλήσεις − επιστροφές)</div>
        <table>
            <tr class="small"><td>Συντ.</td><td class="r">Καθαρή</td><td class="r">ΦΠΑ</td><td class="r">Σύνολο</td></tr>
            @foreach ($report['vat'] as $v)
                <tr><td>{{ rtrim(rtrim(number_format($v['rate'], 2, ',', ''), '0'), ',') }}%</td><td class="r">{{ $money($v['net']) }}</td><td class="r">{{ $money($v['vat']) }}</td><td class="r">{{ $money($v['gross']) }}</td></tr>
            @endforeach
        </table>
    @endif

    <hr>
    <div class="b">Μετρητά συρταριού</div>
    <table>
        <tr><td>Ρέστα ανοίγματος</td><td class="r">{{ $money($report['opening_float']) }} €</td></tr>
        <tr><td>+ Πωλήσεις μετρητοίς</td><td class="r">{{ $money($report['cash_sales']) }} €</td></tr>
        <tr><td>− Επιστροφές μετρητοίς</td><td class="r">{{ $money($report['cash_refunds']) }} €</td></tr>
        @if (($report['pending_cash'] ?? 0) != 0)
            <tr><td>± Εκκρεμή (μη εκδοθέντα)</td><td class="r">{{ $money($report['pending_cash']) }} €</td></tr>
        @endif
        <tr><td>+ Καταθέσεις στο ταμείο</td><td class="r">{{ $money($report['cash_in']) }} €</td></tr>
        <tr><td>− Αναλήψεις από το ταμείο</td><td class="r">{{ $money($report['cash_out']) }} €</td></tr>
        <tr class="b"><td>Αναμενόμενα</td><td class="r">{{ $money($report['expected_cash']) }} €</td></tr>
        @if (! $session->isOpen())
            <tr class="b"><td>Καταμετρήθηκαν</td><td class="r">{{ $money($session->counted_cash) }} €</td></tr>
            @php($diff = round((float) $session->counted_cash - (float) $report['expected_cash'], 2))
            <tr class="b big"><td>Διαφορά</td><td class="r">{{ $diff > 0 ? '+' : ($diff < 0 ? '−' : '') }}{{ $money(abs($diff)) }} €</td></tr>
        @endif
    </table>

    @if (($report['pending'] ?? []) !== [])
        <hr>
        <div class="b">⚠ Εκκρεμή — δεν είχαν εκδοθεί στο κλείσιμο</div>
        <table>
            @foreach ($report['pending'] as $p)
                <tr><td>{{ $p['code'] }}</td><td class="r">{{ $money($p['amount']) }} €</td></tr>
            @endforeach
        </table>
        <div class="small">Ολοκληρώνονται από τα «Παραστατικά» — τα μετρητά τους είναι ήδη στο συρτάρι.</div>
    @endif

    @if ($report['movements'] !== [])
        <hr>
        <div class="b">Κινήσεις μετρητών</div>
        <table>
            @foreach ($report['movements'] as $mv)
                <tr><td>{{ $mv['at'] }} {{ $mv['reason'] }}@if ($mv['user']) <span class="small">({{ $mv['user'] }})</span>@endif</td><td class="r">{{ $mv['direction'] === 'in' ? '+' : '−' }}{{ $money($mv['amount']) }}</td></tr>
            @endforeach
        </table>
    @endif

    @if (filled($session->notes))
        <hr>
        <div class="small">Σημειώσεις: {{ $session->notes }}</div>
    @endif

    <script>
        window.addEventListener('load', () => { window.focus(); window.print(); });
    </script>
</body>
</html>
