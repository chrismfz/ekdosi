<!DOCTYPE html>
<html lang="el">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Αξιολόγηση εξυπηρέτησης — {{ $ticket->reference }}</title>
    <style>
        :root { color-scheme: light dark; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
            background: #f4f4f5; color: #18181b; font: 15px/1.5 system-ui, -apple-system, Segoe UI, Roboto, sans-serif; padding: 1.5rem; }
        .card { background: #fff; border: 1px solid #e4e4e7; border-radius: 16px; padding: 2rem; max-width: 32rem; width: 100%;
            box-shadow: 0 1px 3px rgba(0,0,0,.06); }
        h1 { font-size: 1.25rem; margin: 0 0 .25rem; }
        .ref { color: #71717a; font-size: .85rem; margin-bottom: 1.25rem; }
        .subj { font-weight: 600; margin-bottom: 1rem; }
        .stars { display: flex; flex-wrap: wrap; gap: .5rem; margin: .5rem 0 1rem; }
        .stars label { cursor: pointer; border: 1px solid #d4d4d8; border-radius: 10px; padding: .5rem .75rem; font-size: .95rem; user-select: none; }
        /* visually hidden but still focusable (so native "required" validation can focus it) */
        .stars input { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }
        .stars label:has(input:checked) { border-color: #2563eb; background: #eff6ff; color: #1d4ed8; font-weight: 600; }
        textarea { width: 100%; border: 1px solid #d4d4d8; border-radius: 10px; padding: .625rem; font: inherit; resize: vertical; }
        label.field { display: block; font-size: .85rem; color: #52525b; margin: .75rem 0 .35rem; }
        button { margin-top: 1.25rem; background: #2563eb; color: #fff; border: 0; border-radius: 10px; padding: .7rem 1.25rem; font: inherit; font-weight: 600; cursor: pointer; }
        button:hover { background: #1d4ed8; }
        .ok { background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46; border-radius: 10px; padding: .75rem 1rem; margin-bottom: 1rem; }
        .muted { color: #71717a; }
        @media (prefers-color-scheme: dark) {
            body { background: #18181b; color: #f4f4f5; }
            .card { background: #27272a; border-color: #3f3f46; }
            .stars label { border-color: #52525b; }
            .stars label:has(input:checked) { background: #1e3a8a33; color: #93c5fd; border-color: #3b82f6; }
            textarea { background: #18181b; border-color: #52525b; color: #f4f4f5; }
            .ok { background: #064e3b55; border-color: #065f46; color: #a7f3d0; }
        }
    </style>
</head>
<body>
    <div class="card">
        <h1>Πώς σας φάνηκε η εξυπηρέτηση;</h1>
        <div class="ref">Αίτημα {{ $ticket->reference }}</div>
        <div class="subj">{{ $ticket->subject }}</div>

        @if (session('status'))
            <div class="ok">{{ session('status') }}</div>
        @endif

        @if ($ratable)
            <form method="POST" action="{{ $storeUrl }}">
                @csrf
                <div class="stars">
                    @for ($i = 1; $i <= 5; $i++)
                        <label>
                            <input type="radio" name="rating" value="{{ $i }}" required @checked((int) old('rating', $ticket->rating) === $i)>
                            {{ $i }} ★
                        </label>
                    @endfor
                </div>
                @error('rating') <div class="muted" style="color:#dc2626">{{ $message }}</div> @enderror

                <label class="field" for="rating_comment">Σχόλιο (προαιρετικά)</label>
                <textarea id="rating_comment" name="rating_comment" rows="3">{{ old('rating_comment', $ticket->rating_comment) }}</textarea>
                @error('rating_comment') <div class="muted" style="color:#dc2626">{{ $message }}</div> @enderror

                <button type="submit">Υποβολή αξιολόγησης</button>
            </form>
        @else
            <p class="muted">Η αξιολόγηση δεν είναι διαθέσιμη για αυτό το αίτημα αυτή τη στιγμή
                @if ($ticket->isRated()) — έχει ήδη καταχωρηθεί ({{ $ticket->rating }}/5). Ευχαριστούμε!@endif
            </p>
        @endif
    </div>
</body>
</html>
