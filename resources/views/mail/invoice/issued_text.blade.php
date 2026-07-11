{{-- DOC-8 Finding A: the plain-text MIME part. Rendered as a normal Blade
     view (NOT markdown), so $bodyText is the UN-escaped body — no CommonMark
     backslash-escaping leaks into text-only clients. --}}
@if($tenant?->name){{ $tenant->name }}

@endif
{{ $bodyText }}
@if($tenant?->phone || $tenant?->email || $tenant?->afm)

@php
    $contact = [];
    if ($tenant?->phone) { $contact[] = 'Τηλέφωνο: '.$tenant->phone; }
    if ($tenant?->email) { $contact[] = 'Email: '.$tenant->email; }
    if ($tenant?->afm)   { $contact[] = 'ΑΦΜ: '.$tenant->afm; }
@endphp
{{ implode(' · ', $contact) }}
@endif
