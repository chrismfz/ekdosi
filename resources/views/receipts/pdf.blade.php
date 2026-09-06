@php use App\Support\Money; @endphp
<!DOCTYPE html>
<html lang="el">
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
                    @if ($tenant?->afm) ΑΦΜ {{ $tenant->afm }}@endif
                    @if ($tenant?->tax_office) · ΔΟΥ {{ $tenant->tax_office }}@endif<br>
                    @if ($tenant?->address){{ $tenant->address }}@endif
                    @if ($tenant?->postcode) {{ $tenant->postcode }}@endif
                    @if ($tenant?->city) {{ $tenant->city }}@endif<br>
                    @if ($tenant?->phone)Τηλ. {{ $tenant->phone }}@endif
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

    <h1>ΑΠΟΔΕΙΞΗ ΕΙΣΠΡΑΞΗΣ</h1>
    <div class="muted">Άτυπο αποδεικτικό — δεν αποτελεί φορολογικό παραστατικό</div>

    <table class="meta">
        <tr><td class="k">Πελάτης</td><td>{{ $customerName }}@if ($customerAfm) · ΑΦΜ {{ $customerAfm }}@endif</td></tr>
        <tr><td class="k">Ημερομηνία</td><td>{{ $date }}</td></tr>
        @if ($reference)<tr><td class="k">Αριθμός αναφοράς</td><td>{{ $reference }}</td></tr>@endif
        <tr><td class="k">Κανάλι / τρόπος</td><td>{{ $channel }}</td></tr>
        @if ($transactionId)<tr><td class="k">Κωδικός συναλλαγής</td><td>{{ $transactionId }}</td></tr>@endif
    </table>

    <table class="lines">
        <thead>
            <tr><th>Αφορά</th><th class="amt">Ποσό</th></tr>
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

    <div class="total">Σύνολο είσπραξης: {{ Money::eur($total) }}</div>

    <div class="note">
        Η παρούσα βεβαιώνει την είσπραξη του ανωτέρω ποσού. Δεν υποκαθιστά το φορολογικό
        παραστατικό (τιμολόγιο/απόδειξη) που εκδίδεται μέσω myDATA.
    </div>
</body>
</html>
