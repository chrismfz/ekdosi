@php($e = $leave->employee)
<p>Καλησπέρα,</p>
@if ($event === 'cancelled')
    <p><strong>Ακυρώθηκε</strong> η παρακάτω άδεια που είχε εγκριθεί — παρακαλούμε ενημερώστε/ανακαλέστε τη δήλωση στο ΕΡΓΑΝΗ αν είχε γίνει:</p>
@else
    <p>Εγκρίθηκε η παρακάτω άδεια — παρακαλούμε για τη δήλωσή της στο ΕΡΓΑΝΗ:</p>
@endif
<table cellpadding="4" style="border-collapse:collapse">
    <tr><td><strong>Εργαζόμενος</strong></td><td>{{ $e?->full_name }}@if ($e?->afm) (ΑΦΜ {{ $e->afm }})@endif</td></tr>
    <tr><td><strong>Τύπος</strong></td><td>{{ $leave->type?->getLabel() }} ({{ $leave->type?->value }})</td></tr>
    <tr><td><strong>Διάστημα</strong></td><td>{{ $leave->periodLabel() }}</td></tr>
    <tr><td><strong>Εργάσιμες ημέρες</strong></td><td>{{ $leave->days }}</td></tr>
    @if ($leave->decision_note)
        <tr><td><strong>Σημείωση</strong></td><td>{{ $leave->decision_note }}</td></tr>
    @endif
</table>
<p>— {{ $companyName }} (αυτόματο μήνυμα από το ekdosi)</p>
