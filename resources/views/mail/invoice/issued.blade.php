@component('mail::message')
@if($tenant?->name)
# {{ $tenant->name }}
@endif

{{-- Operator-editable body, already-interpolated by MailTemplateRenderer.
     {!! ... !!} would let HTML escape; we use {!! nl2br(e($body)) !!} so
     newlines render as <br> in HTML but operator-supplied tags are
     escaped to plain text. This is the security boundary between
     operator-edited template strings and rendered HTML.
     autolink() then wraps bare http(s) URLs (the AADE {verify_url}) in a
     clickable <a href> — it runs on the ALREADY-escaped string, so the DOC-8
     boundary above is preserved (see MailTemplateRenderer::autolink). --}}
{!! \App\Services\MailTemplateRenderer::autolink(nl2br(e($body))) !!}

@if($tenant?->phone || $tenant?->email || $tenant?->afm || $tenant?->gemi)
@component('mail::subcopy')
@if($tenant?->phone) {{ __('mail.invoice.contact.phone') }}: {{ $tenant->phone }} @endif
@if($tenant?->email) · {{ __('mail.invoice.contact.email') }}: {{ $tenant->email }} @endif
@if($tenant?->afm) · {{ __('mail.invoice.contact.afm') }}: {{ $tenant->afm }} @endif
@if($tenant?->gemi) · {{ __('mail.invoice.contact.gemi') }}: {{ $tenant->gemi }} @endif
@endcomponent
@endif
@endcomponent
