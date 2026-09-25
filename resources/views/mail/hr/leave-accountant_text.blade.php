@php($e = $leave->employee)
Καλησπέρα,

{{ $intro }}

Εργαζόμενος: {{ $e?->full_name }}@if ($e?->afm) (ΑΦΜ {{ $e->afm }})@endif

Τύπος: {{ $leave->type?->getLabel() }} ({{ $leave->type?->value }})
Διάστημα: {{ $leave->periodLabel() }}
Εργάσιμες ημέρες: {{ $leave->days }}
@if ($erganiLine)
ΕΡΓΑΝΗ: {{ $erganiLine }}
@endif
@if ($leave->decision_note)
Σημείωση: {{ $leave->decision_note }}
@endif

— {{ $companyName }} (αυτόματο μήνυμα από το ekdosi)
