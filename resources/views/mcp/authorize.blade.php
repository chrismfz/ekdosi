{{--
    OAuth 2.1 consent screen for the ekdosi MCP server (Passport). Shown when a
    logged-in operator approves an external client (e.g. the claude.ai connector).

    SELF-CONTAINED on purpose: ekdosi ships no Tailwind utility layer and no Vite
    build (see CLAUDE.md «No-build CSS»), and this page renders OUTSIDE the Filament
    panel, so it carries its own inline styles rather than @vite/utility classes.
    Registered via Passport::authorizationView() in AppServiceProvider. See MCP.md §6.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Έγκριση εφαρμογής — {{ config('app.name', 'ekdosi') }}</title>
    <style>
        :root {
            --bg: #f3f4f6; --card: #ffffff; --fg: #111827; --muted: #6b7280;
            --border: #e5e7eb; --accent: #2563eb; --accent-fg: #ffffff; --soft: #f9fafb;
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #0b0f19; --card: #111827; --fg: #f3f4f6; --muted: #9ca3af;
                --border: #1f2937; --accent: #3b82f6; --accent-fg: #ffffff; --soft: #0f1523;
            }
        }
        * { box-sizing: border-box; }
        body {
            margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
            padding: 1rem; background: var(--bg); color: var(--fg);
            font-family: system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
        }
        .card {
            width: 100%; max-width: 28rem; background: var(--card); border: 1px solid var(--border);
            border-radius: 0.75rem; box-shadow: 0 1px 3px rgba(0,0,0,.08); overflow: hidden;
        }
        .body { padding: 1.5rem; }
        .icon { display: flex; justify-content: center; margin-bottom: 1rem; color: var(--accent); }
        h1 { font-size: 1.35rem; font-weight: 600; text-align: center; margin: 0 0 .35rem; }
        .sub { font-size: .875rem; color: var(--muted); text-align: center; margin: 0 0 1.25rem; }
        .panel { background: var(--soft); border: 1px solid var(--border); border-radius: .5rem; padding: 1rem; margin-bottom: 1rem; }
        .panel .label { font-size: .8rem; color: var(--muted); margin: 0 0 .25rem; }
        .panel .value { font-weight: 500; margin: 0; word-break: break-all; }
        ul { list-style: none; padding: 0; margin: .5rem 0 0; }
        li { display: flex; gap: .5rem; align-items: flex-start; font-size: .875rem; color: var(--muted); margin-bottom: .4rem; }
        li::before { content: "•"; color: var(--accent); }
        .actions { display: flex; gap: .75rem; padding: 0 1.5rem 1.5rem; }
        form { flex: 1; margin: 0; }
        button {
            width: 100%; padding: .6rem 1rem; border-radius: .5rem; font-size: .9rem; font-weight: 500;
            cursor: pointer; border: 1px solid var(--border);
        }
        .approve { background: var(--accent); color: var(--accent-fg); border-color: var(--accent); }
        .deny { background: transparent; color: var(--fg); }
    </style>
</head>
<body>
    <div class="card">
        <div class="body">
            <div class="icon" aria-hidden="true">
                <svg width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M20.618 5.984A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.031 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                </svg>
            </div>

            <h1>Έγκριση «{{ $client->name }}»</h1>
            <p class="sub">Η εφαρμογή θα αποκτήσει πρόσβαση στα εργαλεία MCP του ekdosi για τον λογαριασμό σας.</p>

            <div class="panel">
                <p class="label">Συνδεδεμένος ως</p>
                <p class="value">{{ $user->email }}</p>
            </div>

            @if(count($scopes) > 0)
                <div class="panel">
                    <p class="label">Δικαιώματα</p>
                    <ul>
                        @foreach($scopes as $scope)
                            <li>{{ $scope->description }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>

        <div class="actions">
            <form method="POST" action="{{ route('passport.authorizations.deny') }}">
                @csrf
                @method('DELETE')
                <input type="hidden" name="state" value="{{ $request->state ?? '' }}">
                <input type="hidden" name="client_id" value="{{ $client->id }}">
                <input type="hidden" name="auth_token" value="{{ $authToken }}">
                <button type="submit" class="deny">Άκυρο</button>
            </form>

            <form method="POST" action="{{ route('passport.authorizations.approve') }}">
                @csrf
                <input type="hidden" name="state" value="{{ $request->state ?? '' }}">
                <input type="hidden" name="client_id" value="{{ $client->id }}">
                <input type="hidden" name="auth_token" value="{{ $authToken }}">
                <button type="submit" class="approve">Έγκριση</button>
            </form>
        </div>
    </div>
</body>
</html>
