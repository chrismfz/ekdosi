@php
    $user = auth()->user();
    $noCompany = $user
        && method_exists($user, 'companies')
        && ! $user->companies()->exists();
    $logoutRoute = \Illuminate\Support\Facades\Route::has('filament.admin.auth.logout')
        ? route('filament.admin.auth.logout')
        : null;
@endphp
<!doctype html>
<html lang="el">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>403 — Δεν επιτρέπεται</title>
    <style>
        body { margin: 0; font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
               background: #f3f4f6; color: #111827; display: flex; min-height: 100vh;
               align-items: center; justify-content: center; padding: 1.5rem; }
        .card { background: #fff; max-width: 30rem; width: 100%; border-radius: 0.75rem;
                box-shadow: 0 10px 25px rgba(0,0,0,.08); padding: 2rem; }
        .tag { display: inline-block; font-size: .75rem; font-weight: 600; letter-spacing: .05em;
               color: #b91c1c; background: #fee2e2; padding: .15rem .5rem; border-radius: .375rem; }
        h1 { font-size: 1.25rem; margin: .75rem 0 .5rem; }
        p { color: #4b5563; line-height: 1.5; margin: .25rem 0 1.25rem; }
        .actions { display: flex; gap: .75rem; align-items: center; }
        button, a.btn { font: inherit; cursor: pointer; border: 0; border-radius: .5rem;
               padding: .55rem 1rem; text-decoration: none; }
        button { background: #f59e0b; color: #111827; font-weight: 600; }
        a.btn { background: #e5e7eb; color: #111827; }
    </style>
</head>
<body>
    <div class="card">
        <span class="tag">403</span>
        @if ($noCompany)
            <h1>Ο λογαριασμός σου δεν έχει ανατεθεί σε εταιρεία</h1>
            <p>Για να χρησιμοποιήσεις την εφαρμογή, ένας διαχειριστής πρέπει να σε
               συνδέσει με τουλάχιστον μία εταιρεία (Users → ο λογαριασμός σου →
               «Companies»). Επικοινώνησε μαζί του.</p>
        @else
            <h1>Δεν έχεις πρόσβαση σε αυτή τη σελίδα</h1>
            <p>{{ $exception?->getMessage() ?: 'Δεν έχεις δικαίωμα πρόσβασης στο συγκεκριμένο περιεχόμενο.' }}</p>
        @endif

        <div class="actions">
            @if ($logoutRoute)
                <form method="POST" action="{{ $logoutRoute }}">
                    @csrf
                    <button type="submit">Αποσύνδεση</button>
                </form>
            @endif
            <a class="btn" href="{{ url('/admin') }}">Επιστροφή</a>
        </div>
    </div>
</body>
</html>
