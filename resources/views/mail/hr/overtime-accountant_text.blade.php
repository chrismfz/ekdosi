@php($e = $o->employee)
Καλησπέρα,

{{ $intro }}

Εργαζόμενος: {{ $e?->full_name }}@if ($e?->afm) (ΑΦΜ {{ $e->afm }})@endif

Υπερωρία: {{ $o->slotLabel() }}
@if ($erganiLine)
ΕΡΓΑΝΗ: {{ $erganiLine }}
@endif

— {{ $companyName }} (αυτόματο μήνυμα από το ekdosi)
