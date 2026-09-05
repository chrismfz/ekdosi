<!DOCTYPE html>
<html lang="el">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Ο λογαριασμός μου</title>
    <style>
        :root { color-scheme: light dark; }
        body {
            margin: 0; min-height: 100vh; font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
            background: #f4f5f7; color: #1f2430;
        }
        .bar {
            display: flex; align-items: center; justify-content: space-between;
            background: #fff; border-bottom: 1px solid #e3e6eb; padding: 12px 20px;
        }
        .bar strong { font-size: 15px; }
        .logout { border: 0; background: transparent; color: #2563eb; font-size: 14px; cursor: pointer; }
        .logout:hover { text-decoration: underline; }
        .wrap { max-width: 720px; margin: 32px auto; padding: 0 20px; }
        .card { background: #fff; border: 1px solid #e3e6eb; border-radius: 12px; padding: 24px; }
        h1 { font-size: 20px; margin: 0 0 6px; }
        p { color: #4b5563; line-height: 1.5; }
        @media (prefers-color-scheme: dark) {
            body { background: #16181d; color: #e5e7eb; }
            .bar { background: #1f232b; border-color: #2c313b; }
            .card { background: #1f232b; border-color: #2c313b; }
            p { color: #9ca3af; }
        }
    </style>
</head>
<body>
    <div class="bar">
        <strong>Ο λογαριασμός μου</strong>
        <form method="POST" action="{{ route('portal.logout') }}">
            @csrf
            <button type="submit" class="logout">Αποσύνδεση</button>
        </form>
    </div>
    <div class="wrap">
        <div class="card">
            <h1>Καλωσήρθες, {{ $user->name }}</h1>
            <p>Είσαι συνδεδεμένος ως <strong>{{ $user->email }}</strong>.</p>
            <p>Σύντομα εδώ θα βλέπεις τα παραστατικά και τα στοιχεία σου.</p>
        </div>
    </div>
</body>
</html>
