@php($e = $leave->employee)
<p>Καλησπέρα,</p>
<p>{{ $intro }}</p>
<table cellpadding="4" style="border-collapse:collapse">
    <tr><td><strong>Εργαζόμενος</strong></td><td>{{ $e?->full_name }}@if ($e?->afm) (ΑΦΜ {{ $e->afm }})@endif</td></tr>
    <tr><td><strong>Τύπος</strong></td><td>{{ $leave->type?->getLabel() }} ({{ $leave->type?->value }})</td></tr>
    <tr><td><strong>Διάστημα</strong></td><td>{{ $leave->periodLabel() }}</td></tr>
    <tr><td><strong>Εργάσιμες ημέρες</strong></td><td>{{ $leave->days }}</td></tr>
    @if ($leave->decision_note)
        <tr><td><strong>Σημείωση</strong></td><td>{{ $leave->decision_note }}</td></tr>
    @endif
    @if ($erganiLine)
        <tr><td><strong>ΕΡΓΑΝΗ</strong></td><td>{{ $erganiLine }}</td></tr>
    @endif
</table>
<p>— {{ $companyName }} (αυτόματο μήνυμα από το ekdosi)</p>
