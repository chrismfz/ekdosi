@component('mail::message')
# {{ __('mail.quote.heading', ['code' => $quote->code]) }}

{{ __('mail.quote.greeting') }}

@if($quote->subject)
{{ __('mail.quote.intro_with', ['subject' => $quote->subject]) }}
@else
{{ __('mail.quote.intro') }}
@endif

@if ($quote->valid_until)
**{{ __('mail.quote.valid_until') }}** {{ $quote->valid_until->format('d/m/Y') }}
@endif

**{{ __('mail.quote.total') }}** {{ number_format((float) $quote->gross_total, 2, ',', '.') }} €

@if ($quote->customer_notes)
{{ $quote->customer_notes }}
@endif

{{ __('mail.quote.closing') }}

{{ __('mail.common.regards') }}
{{ $tenant->name }}

<small>{{ __('mail.quote.not_tax_doc') }}</small>
@endcomponent
