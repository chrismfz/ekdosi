<x-filament-panels::page>
    <style>
        .wk { display:flex; flex-direction:column; align-items:center; gap:1rem; text-align:center; padding:1rem 0; }
        .wk img { width:min(70vw, 320px); height:auto; background:#fff; border-radius:1rem; padding:.5rem; }
        .wk-note { font-size:.95rem; color:#6b7280; max-width:40rem; } .dark .wk-note { color:#9ca3af; }
        .wk-url { font-family:ui-monospace, monospace; font-size:.95rem; padding:.3rem .6rem; border-radius:.4rem; background:#f3f4f6; } .dark .wk-url { background:rgba(255,255,255,.08); }
        .wk-tbl { width:100%; border-collapse:collapse; font-size:.9rem; }
        .wk-tbl th, .wk-tbl td { text-align:left; padding:.5rem .6rem; border-bottom:1px solid #e5e7eb; vertical-align:middle; } .dark .wk-tbl th, .dark .wk-tbl td { border-color:rgba(255,255,255,.1); }
        .wk-tbl th { font-weight:600; color:#6b7280; } .dark .wk-tbl th { color:#9ca3af; }
        .wk-muted { color:#6b7280; font-size:.8rem; } .dark .wk-muted { color:#9ca3af; }
        .wk-scroll { overflow-x:auto; }
    </style>

    <x-filament::section heading="Συσκευές (tablet γραφείου)">
        <p class="wk-note" style="text-align:left">
            Κάθε tablet ανοίγει <span class="wk-url">{{ $kioskUrl }}</span> <strong>χωρίς σύνδεση</strong> — την εταιρεία την ξέρει από την ενεργοποίηση.
            Για νέο tablet: συνδεθείτε σε αυτό μία φορά, πατήστε «Ενεργοποίηση αυτής της συσκευής» και μετά <strong>αποσυνδεθείτε</strong>.
        </p>
        @if ($devices->isEmpty())
            <p class="wk-note" style="text-align:left">Δεν υπάρχει ενεργοποιημένη συσκευή.</p>
        @else
            <div class="wk-scroll">
                <table class="wk-tbl">
                    <thead><tr><th>Συσκευή</th><th>Ενεργοποίηση</th><th>Τελευταία φορά</th><th>IP / browser</th><th></th></tr></thead>
                    <tbody>
                        @foreach ($devices as $d)
                            <tr wire:key="dev-{{ $d->id }}">
                                <td><strong>{{ $d->name }}</strong></td>
                                <td>{{ $d->created_at->format('d/m/Y H:i') }}<div class="wk-muted">{{ $d->activatedBy?->name ?? '—' }}</div></td>
                                <td>{{ $d->last_seen_at ? $d->last_seen_at->diffForHumans() : 'ποτέ' }}</td>
                                <td>{{ $d->last_ip ?? '—' }}<div class="wk-muted">{{ \Illuminate\Support\Str::limit($d->last_user_agent ?? '', 60) }}</div></td>
                                <td>{{ ($this->revokeDeviceAction)(['device' => $d->id]) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>

    <x-filament::section heading="QR (για κινητά)">
        <div class="wk">
            <img src="{{ $qr }}" alt="QR κάρτας εργασίας">
            <div class="wk-note"><strong>{{ $company }}</strong> — σκανάρετε με το κινητό σας (συνδεδεμένοι στο ekdosi) για να χτυπήσετε κάρτα. Στιγμιότυπο — ανανεώστε τη σελίδα· τα tablet ανανεώνονται μόνα τους.</div>
        </div>
    </x-filament::section>
</x-filament-panels::page>
