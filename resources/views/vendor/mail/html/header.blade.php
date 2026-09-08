@props(['url'])
{{-- ekdosi: the app-name banner (Laravel's default mail header renders
     config('app.name') — the INTERNAL tool name «ekdosi») is intentionally NOT
     shown in customer-facing mail. Every message already leads with the ISSUING
     COMPANY's name (the H1 in the body) and carries its contact block in the
     subcopy, so the internal name only confused test recipients. Per-file vendor
     override: only the header is dropped — the rest of the mail theme still tracks
     the framework default. Change config('app.name')/APP_NAME instead ONLY if you
     also mean to rename the panel + page titles (this file is the mail-only lever). --}}
