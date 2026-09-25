@php($e = $leave->employee)
Καλησπέρα,

@if ($event === 'cancelled')
ΑΚΥΡΩΘΗΚΕ η παρακάτω άδεια που είχε εγκριθεί — παρακαλούμε ενημερώστε/ανακαλέστε τη δήλωση στο ΕΡΓΑΝΗ αν είχε γίνει:
@else
Εγκρίθηκε η παρακάτω άδεια — παρακαλούμε για τη δήλωσή της στο ΕΡΓΑΝΗ:
@endif

Εργαζόμενος: {{ $e?->full_name }}@if ($e?->afm) (ΑΦΜ {{ $e->afm }})@endif

Τύπος: {{ $leave->type?->getLabel() }} ({{ $leave->type?->value }})
Διάστημα: {{ $leave->periodLabel() }}
Εργάσιμες ημέρες: {{ $leave->days }}
@if ($leave->decision_note)
Σημείωση: {{ $leave->decision_note }}
@endif

— {{ $companyName }} (αυτόματο μήνυμα από το ekdosi)
