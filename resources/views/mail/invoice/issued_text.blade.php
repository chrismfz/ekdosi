{{-- DOC-8 Finding A: the plain-text MIME part. Rendered as a normal Blade
     view (NOT markdown), so $bodyText is the UN-escaped body — no CommonMark
     backslash-escaping leaks into text-only clients.
     {!! … !!}, not {{ … }}: this is the text/plain part — a mail client renders
     it as literal text and NEVER parses it as HTML, so there is no injection
     vector to escape against, while {{ }} would corrupt content (a URL's `&`
     → `&amp;`, breaking the verification link when copied). Raw is correct here
     and matches this view's "UN-escaped body" intent. --}}
@if($tenant?->name){!! $tenant->name !!}

@endif
{!! $bodyText !!}
@if($tenant?->phone || $tenant?->email || $tenant?->afm || $tenant?->gemi)

@php
    $contact = [];
    if ($tenant?->phone) { $contact[] = 'Τηλέφωνο: '.$tenant->phone; }
    if ($tenant?->email) { $contact[] = 'Email: '.$tenant->email; }
    if ($tenant?->afm)   { $contact[] = 'ΑΦΜ: '.$tenant->afm; }
    if ($tenant?->gemi)  { $contact[] = 'ΓΕΜΗ: '.$tenant->gemi; }
@endphp
{!! implode(' · ', $contact) !!}
@endif
