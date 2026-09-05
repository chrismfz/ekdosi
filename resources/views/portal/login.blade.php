<!DOCTYPE html>
<html lang="el">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Είσοδος πελατών</title>
    <style>
        :root { color-scheme: light dark; }
        * { box-sizing: border-box; }
        body {
            margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
            font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
            background: #f4f5f7; color: #1f2430; padding: 20px;
        }
        .card {
            width: 100%; max-width: 380px; background: #fff; border: 1px solid #e3e6eb;
            border-radius: 12px; padding: 28px 26px; box-shadow: 0 1px 3px rgba(0,0,0,.06);
        }
        h1 { font-size: 20px; margin: 0 0 4px; }
        .sub { color: #6b7280; font-size: 13px; margin: 0 0 20px; }
        label { display: block; font-size: 13px; font-weight: 600; margin: 14px 0 6px; }
        input[type=email], input[type=password] {
            width: 100%; padding: 10px 12px; border: 1px solid #cfd4dc; border-radius: 8px;
            font-size: 14px; background: #fff; color: inherit;
        }
        input:focus { outline: 2px solid #2563eb33; border-color: #2563eb; }
        .row { display: flex; align-items: center; gap: 8px; margin-top: 14px; font-size: 13px; color: #4b5563; }
        button {
            width: 100%; margin-top: 20px; padding: 11px; border: 0; border-radius: 8px;
            background: #2563eb; color: #fff; font-size: 15px; font-weight: 600; cursor: pointer;
        }
        button:hover { background: #1d4ed8; }
        .alert {
            background: #fef2f2; border: 1px solid #fecaca; color: #b91c1c;
            border-radius: 8px; padding: 10px 12px; font-size: 13px; margin-bottom: 16px;
        }
        @media (prefers-color-scheme: dark) {
            body { background: #16181d; color: #e5e7eb; }
            .card { background: #1f232b; border-color: #2c313b; box-shadow: none; }
            .sub { color: #9ca3af; }
            input[type=email], input[type=password] { background: #12151a; border-color: #39404c; }
            .alert { background: #2a1414; border-color: #5b2020; color: #fca5a5; }
        }
    </style>
</head>
<body>
    <div class="card">
        <h1>Είσοδος πελατών</h1>
        <p class="sub">Δες τα παραστατικά και τα στοιχεία σου.</p>

        @if ($errors->any())
            <div class="alert">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('portal.login.attempt') }}">
            @csrf
            <label for="email">Email</label>
            <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username">

            <label for="password">Κωδικός</label>
            <input id="password" type="password" name="password" required autocomplete="current-password">

            <div class="row">
                <input id="remember" type="checkbox" name="remember" value="1">
                <label for="remember" style="margin:0; font-weight:400;">Να με θυμάσαι</label>
            </div>

            <button type="submit">Είσοδος</button>
        </form>
    </div>
</body>
</html>
