<!DOCTYPE html>
<html lang="el">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Κάρτα εργασίας{{ $company ? ' — '.$company : '' }}</title>
    <style>
        * { box-sizing:border-box; }
        body { margin:0; min-height:100vh; font-family:system-ui, sans-serif; background:#0f172a; color:#f8fafc; }
        .wrap { display:grid; grid-template-columns:minmax(0,1fr) auto; gap:1.5rem; padding:1.25rem; max-width:1200px; margin:0 auto; }
        @media (max-width: 860px) { .wrap { grid-template-columns:1fr; } }
        header { display:flex; justify-content:space-between; align-items:baseline; gap:1rem; padding:1rem 1.25rem 0; max-width:1200px; margin:0 auto; }
        .clock { font-size:clamp(2rem, 6vw, 3.5rem); font-weight:700; font-variant-numeric:tabular-nums; }
        .co { opacity:.8; font-size:1.1rem; }
        .board { display:grid; grid-template-columns:repeat(auto-fill, minmax(220px, 1fr)); gap:.75rem; align-content:start; }
        .emp { border:0; border-radius:1rem; padding:1rem; text-align:left; cursor:pointer; background:#1e293b; color:inherit; font:inherit; display:flex; flex-direction:column; gap:.35rem; min-height:92px; }
        .emp:hover { background:#273449; } .emp:disabled { opacity:.45; cursor:not-allowed; }
        .emp .n { font-size:1.15rem; font-weight:600; }
        .emp .s { font-size:.95rem; opacity:.85; }
        .in { box-shadow: inset 6px 0 0 #22c55e; } .out { box-shadow: inset 6px 0 0 #64748b; }
        .dot { display:inline-block; width:.7rem; height:.7rem; border-radius:50%; margin-right:.4rem; vertical-align:middle; }
        .qr { text-align:center; }
        .qr img { width:220px; height:auto; background:#fff; border-radius:1rem; padding:.5rem; }
        .qr p { font-size:.85rem; opacity:.75; max-width:240px; }
        .count { opacity:.8; margin:0 0 .75rem; }
        dialog { border:0; border-radius:1.25rem; padding:1.5rem; background:#1e293b; color:#f8fafc; width:min(92vw, 360px); }
        dialog::backdrop { background:rgba(0,0,0,.6); }
        .who { font-size:1.2rem; font-weight:600; margin-bottom:.25rem; }
        .act { margin-bottom:1rem; opacity:.85; }
        .pin { font-size:2rem; letter-spacing:.6rem; text-align:center; min-height:2.6rem; margin-bottom:1rem; }
        .pad { display:grid; grid-template-columns:repeat(3,1fr); gap:.6rem; }
        .pad button, .actions button { border:0; border-radius:.9rem; padding:1rem 0; font-size:1.4rem; background:#334155; color:#f8fafc; cursor:pointer; }
        .actions { display:grid; grid-template-columns:1fr 1fr; gap:.6rem; margin-top:.8rem; }
        .ok { background:#16a34a !important; } .cancel { background:#475569 !important; }
        .msg { margin-top:.8rem; min-height:1.3rem; text-align:center; }
        .toast { position:fixed; left:50%; bottom:2rem; transform:translateX(-50%); background:#16a34a; color:#fff; padding:1rem 1.5rem; border-radius:1rem; font-size:1.1rem; display:none; max-width:90vw; text-align:center; }
        .toast.err { background:#dc2626; }
        .setup { max-width:40rem; margin:20vh auto; text-align:center; font-size:1.1rem; opacity:.85; padding:1rem; }
    </style>
</head>
<body>
@if (! $company)
    <p class="setup">Αυτή η συσκευή δεν είναι ενεργοποιημένη ως σημείο κάρτας. Ένας διαχειριστής συνδέεται εδώ μία φορά,
        ανοίγει «Προσωπικό → Σημείο κάρτας (QR)» και πατά «Ενεργοποίηση αυτής της συσκευής».</p>
@else
    <header>
        <div class="clock" id="clock"></div>
        <div class="co">{{ $company }}</div>
    </header>
    <div class="wrap">
        <section>
            @php($inCount = collect($board)->where('in', true)->count())
            <p class="count">Μέσα τώρα: <strong>{{ $inCount }}</strong> / {{ count($board) }} — πατήστε το όνομά σας για είσοδο/έξοδο.</p>
            <div class="board">
                @foreach ($board as $e)
                    <button type="button" class="emp {{ $e['in'] ? 'in' : 'out' }}" @disabled(! $e['has_pin'])
                        data-id="{{ $e['id'] }}" data-name="{{ $e['name'] }}" data-next="{{ $e['next'] }}">
                        <span class="n">{{ $e['name'] }}</span>
                        <span class="s">
                            <span class="dot" style="background: {{ $e['in'] ? '#22c55e' : '#64748b' }}"></span>
                            {{ $e['in'] ? 'Μέσα από '.$e['since'] : 'Εκτός' }}
                        </span>
                        @unless ($e['has_pin']) <span class="s">(χωρίς PIN)</span> @endunless
                    </button>
                @endforeach
            </div>
        </section>
        <aside class="qr">
            <img src="{{ $qr }}" alt="QR κάρτας εργασίας">
            <p>Ή σκανάρετε με το κινητό σας (συνδεδεμένοι στο ekdosi).</p>
        </aside>
    </div>

    <dialog id="pinDialog">
        <div class="who" id="who"></div>
        <div class="act" id="act"></div>
        <div class="pin" id="pinView"></div>
        <div class="pad">
            @foreach ([1,2,3,4,5,6,7,8,9] as $d)
                <button type="button" data-d="{{ $d }}">{{ $d }}</button>
            @endforeach
            <button type="button" data-back>⌫</button>
            <button type="button" data-d="0">0</button>
            <button type="button" data-clear>C</button>
        </div>
        <div class="actions">
            <button type="button" class="cancel" id="cancelBtn">Άκυρο</button>
            <button type="button" class="ok" id="okBtn">OK</button>
        </div>
        <div class="msg" id="msg"></div>
    </dialog>
    <div class="toast" id="toast"></div>

    <script>
        const clock = document.getElementById('clock');
        const tick = () => clock.textContent = new Date().toLocaleTimeString('el-GR', { hour: '2-digit', minute: '2-digit' });
        tick(); setInterval(tick, 1000);

        const dlg = document.getElementById('pinDialog');
        let current = null, pin = '', busy = false;
        const pinView = document.getElementById('pinView'), msg = document.getElementById('msg');
        const render = () => pinView.textContent = '•'.repeat(pin.length);
        const close = () => { dlg.close(); current = null; pin = ''; msg.textContent = ''; };

        document.querySelectorAll('.emp').forEach(b => b.addEventListener('click', () => {
            current = { id: b.dataset.id, next: b.dataset.next };
            document.getElementById('who').textContent = b.dataset.name;
            document.getElementById('act').textContent = b.dataset.next === 'out' ? 'ΕΞΟΔΟΣ — πληκτρολογήστε το PIN σας' : 'ΕΙΣΟΔΟΣ — πληκτρολογήστε το PIN σας';
            pin = ''; render(); msg.textContent = ''; dlg.showModal();
        }));
        dlg.querySelectorAll('[data-d]').forEach(b => b.addEventListener('click', () => { if (pin.length < 6) { pin += b.dataset.d; render(); } }));
        dlg.querySelector('[data-back]').addEventListener('click', () => { pin = pin.slice(0, -1); render(); });
        dlg.querySelector('[data-clear]').addEventListener('click', () => { pin = ''; render(); });
        document.getElementById('cancelBtn').addEventListener('click', close);

        const toast = (text, err) => {
            const t = document.getElementById('toast');
            t.textContent = text; t.className = 'toast' + (err ? ' err' : ''); t.style.display = 'block';
            setTimeout(() => { t.style.display = 'none'; location.reload(); }, err ? 4000 : 2500);
        };

        document.getElementById('okBtn').addEventListener('click', async () => {
            if (busy || ! current || pin.length < 4) { msg.textContent = 'Το PIN έχει 4–6 ψηφία.'; return; }
            busy = true; msg.textContent = '…';
            try {
                const r = await fetch(@json(route('ergani.card-kiosk.punch')), {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                    body: JSON.stringify({ employee: current.id, pin, seen: current.next }),
                });
                const j = await r.json().catch(() => ({ ok: false, message: 'Σφάλμα (' + r.status + ')' }));
                if (j.ok) { close(); toast(j.message, false); }
                else if (r.status === 422) { pin = ''; render(); msg.textContent = j.message; }
                else { close(); toast(j.message || 'Σφάλμα', true); }
            } catch (e) { close(); toast('Σφάλμα δικτύου — δοκιμάστε ξανά.', true); }
            busy = false;
        });

        // Refresh the board + QR regularly — never while someone is typing a PIN.
        setInterval(() => { if (! dlg.open && ! busy) location.reload(); }, {{ $refresh }} * 1000);
    </script>
@endif
</body>
</html>
