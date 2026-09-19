{{ __('mail.ticket.feedback.title') }}

{{ __('mail.ticket.feedback.closed', ['reference' => $ticket->reference, 'subject' => $ticket->subject]) }}
{{ __('mail.ticket.feedback.ask_text') }}

{{ $url }}

{{ __('mail.ticket.feedback.thanks') }}
{{ config('app.name') }}
