<!DOCTYPE html>
<html lang="el">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Εγκατάσταση') · ekdosi</title>
    <style>
        :root {
            --bg: #0f172a; --card: #ffffff; --ink: #1e293b; --muted: #64748b;
            --line: #e2e8f0; --brand: #2563eb; --brand-ink: #ffffff;
            --ok-bg: #ecfdf5; --ok-ink: #065f46; --ok-line: #a7f3d0;
            --warn-bg: #fffbeb; --warn-ink: #92400e; --warn-line: #fde68a;
            --err-bg: #fef2f2; --err-ink: #991b1b; --err-line: #fecaca;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0; background: var(--bg); color: var(--ink);
            font-family: system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif;
            line-height: 1.5; padding: 32px 16px;
        }
        .wrap { max-width: 720px; margin: 0 auto; }
        .brand { color: #e2e8f0; text-align: center; margin-bottom: 20px; }
        .brand h1 { margin: 0; font-size: 22px; letter-spacing: .5px; }
        .brand p { margin: 6px 0 0; color: #94a3b8; font-size: 14px; }
        .card {
            background: var(--card); border-radius: 14px; padding: 24px 28px;
            box-shadow: 0 10px 30px rgba(0,0,0,.25); margin-bottom: 20px;
        }
        h2 { font-size: 16px; margin: 0 0 4px; }
        .section-hint { color: var(--muted); font-size: 13px; margin: 0 0 16px; }
        .field { margin-bottom: 14px; }
        label { display: block; font-size: 13px; font-weight: 600; margin-bottom: 5px; }
        .req { color: #dc2626; }
        input[type=text], input[type=url], input[type=email], input[type=password],
        input[type=number], select {
            width: 100%; padding: 9px 11px; border: 1px solid var(--line);
            border-radius: 8px; font-size: 14px; color: var(--ink); background: #fff;
        }
        input:focus, select:focus { outline: 2px solid var(--brand); border-color: var(--brand); }
        .row { display: flex; gap: 12px; flex-wrap: wrap; }
        .row > .field { flex: 1; min-width: 160px; }
        .hint { color: var(--muted); font-size: 12px; margin-top: 4px; }
        button {
            font: inherit; cursor: pointer; border: none; border-radius: 8px; padding: 10px 18px;
        }
        .btn-primary { background: var(--brand); color: var(--brand-ink); font-weight: 600; font-size: 15px; }
        .btn-secondary { background: #f1f5f9; color: var(--ink); border: 1px solid var(--line); font-weight: 600; }
        .alert { border-radius: 10px; padding: 12px 14px; font-size: 14px; margin-bottom: 16px; }
        .alert ul { margin: 6px 0 0; padding-left: 20px; }
        .alert-err { background: var(--err-bg); color: var(--err-ink); border: 1px solid var(--err-line); }
        .alert-ok { background: var(--ok-bg); color: var(--ok-ink); border: 1px solid var(--ok-line); }
        .alert-warn { background: var(--warn-bg); color: var(--warn-ink); border: 1px solid var(--warn-line); }
        .token-box {
            font-family: ui-monospace, "SF Mono", Menlo, monospace; font-size: 13px;
            background: #f8fafc; border: 1px dashed var(--line); border-radius: 8px;
            padding: 8px 10px; word-break: break-all;
        }
        .footer { text-align: center; color: #64748b; font-size: 12px; }
        code { background: #f1f5f9; padding: 1px 5px; border-radius: 4px; font-size: 13px; }
        .hidden { display: none; }
        .reqs { list-style: none; margin: 8px 0 0; padding: 0; }
        .req-row { display: flex; gap: 10px; align-items: flex-start; padding: 9px 0; border-top: 1px solid var(--line); }
        .req-row:first-child { border-top: none; }
        .req-ico { font-weight: 700; width: 18px; text-align: center; flex: none; line-height: 1.5; }
        .req-ok .req-ico { color: var(--ok-ink); }
        .req-warn .req-ico { color: var(--warn-ink); }
        .req-error .req-ico { color: var(--err-ink); }
        .req-body { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
        .req-label { font-weight: 600; font-size: 14px; }
        .req-detail { color: var(--muted); font-size: 12.5px; }
        .req-fix { margin-top: 3px; }
        .req-fix code { white-space: pre-wrap; word-break: break-word; display: inline-block; }
        button[disabled] { opacity: .5; cursor: not-allowed; }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="brand">
            <h1>ekdosi</h1>
            <p>@yield('subtitle', 'Οδηγός πρώτης εγκατάστασης')</p>
        </div>
        @yield('content')
        <p class="footer">ekdosi · έκδοση {{ config('app.version') }}</p>
    </div>
    @yield('scripts')
</body>
</html>
