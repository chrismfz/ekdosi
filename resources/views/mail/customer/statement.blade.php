@component('mail::message')
# {{ __('mail.statement.heading') }}

{{ __('mail.statement.greeting', ['name' => $customer->name]) }}

@if ($bodyMessage)
{{ $bodyMessage }}
@else
{{ __('mail.statement.default_body') }}
@endif

@if ($tenant)
{{ __('mail.common.regards') }}
{{ $tenant->name }}
@endif
@endcomponent
