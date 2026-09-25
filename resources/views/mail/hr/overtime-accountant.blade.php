@php($e = $o->employee)
<p>Καλησπέρα,</p>
<p>{{ $intro }}</p>
<table cellpadding="4" style="border-collapse:collapse">
    <tr><td><strong>Εργαζόμενος</strong></td><td>{{ $e?->full_name }}@if ($e?->afm) (ΑΦΜ {{ $e->afm }})@endif</td></tr>
    <tr><td><strong>Υπερωρία</strong></td><td>{{ $o->slotLabel() }}</td></tr>
    @if ($erganiLine)
        <tr><td><strong>ΕΡΓΑΝΗ</strong></td><td>{{ $erganiLine }}</td></tr>
    @endif
</table>
<p>— {{ $companyName }} (αυτόματο μήνυμα από το ekdosi)</p>
