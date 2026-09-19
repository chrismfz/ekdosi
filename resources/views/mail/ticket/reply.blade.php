<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head><meta charset="utf-8"></head>
<body style="font-family: -apple-system, Segoe UI, Roboto, sans-serif; color: #222; line-height: 1.5;">
    <div style="white-space: pre-line;">{{ $body }}</div>
    <hr style="border: none; border-top: 1px solid #eee; margin: 24px 0;">
    <p style="color: #888; font-size: 12px;">
        {{-- The reference is bolded via <strong>; it and the (trusted) footer text are the
             only markup — $ticket->reference is escaped before wrapping, so {!! !!} is safe. --}}
        {!! __('mail.ticket.reply.footer', ['reference' => '<strong>'.e($ticket->reference).'</strong>']) !!}
    </p>
</body>
</html>
