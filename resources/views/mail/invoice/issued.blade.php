@component('mail::message')
@if($tenant?->name)
# {{ $tenant->name }}
@endif

{{-- Operator-editable body, already-interpolated by MailTemplateRenderer.
     {!! ... !!} would let HTML escape; we use {!! nl2br(e($body)) !!} so
     newlines render as <br> in HTML but operator-supplied tags are
     escaped to plain text. This is the security boundary between
     operator-edited template strings and rendered HTML. --}}
{!! nl2br(e($body)) !!}

@if($tenant?->phone || $tenant?->email || $tenant?->afm)
@component('mail::subcopy')
@if($tenant?->phone) Τηλέφωνο: {{ $tenant->phone }} @endif
@if($tenant?->email) · Email: {{ $tenant->email }} @endif
@if($tenant?->afm) · ΑΦΜ: {{ $tenant->afm }} @endif
@endcomponent
@endif
@endcomponent
