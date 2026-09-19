<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head><meta charset="utf-8"></head>
<body style="font-family: -apple-system, Segoe UI, Roboto, sans-serif; color: #222; line-height: 1.5;">
    {{-- Reference bolded via <strong>; both the reference and the customer-supplied
         subject are escaped before interpolation, so {!! !!} carries no injection. --}}
    <p>{!! __('mail.ticket.feedback.closed', ['reference' => '<strong>'.e($ticket->reference).'</strong>', 'subject' => e($ticket->subject)]) !!}</p>
    <p>{{ __('mail.ticket.feedback.ask') }}</p>
    <p style="margin: 24px 0;">
        <a href="{{ $url }}" style="background: #2563eb; color: #fff; text-decoration: none; padding: 12px 20px; border-radius: 8px; font-weight: 600; display: inline-block;">{{ __('mail.ticket.feedback.button') }}</a>
    </p>
    <p style="color: #888; font-size: 12px;">{{ __('mail.ticket.feedback.fallback') }} <br>{{ $url }}</p>
</body>
</html>
