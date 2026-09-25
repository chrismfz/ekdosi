<x-filament-widgets::widget>
    <style>
        .egs { display:grid; gap:.6rem; font-size:.9rem; }
        .egs-row { display:flex; flex-wrap:wrap; gap:.4rem .8rem; align-items:center; }
        .egs-pill { display:inline-block; padding:.1rem .55rem; border-radius:999px; font-size:.75rem; font-weight:600; }
        .egs-trial { background:#fef3c7; color:#92400e; } .dark .egs-trial { background:rgba(251,191,36,.2); color:#fde68a; }
        .egs-prod { background:#dcfce7; color:#166534; } .dark .egs-prod { background:rgba(74,222,128,.2); color:#bbf7d0; }
        .egs-muted { color:#6b7280; } .dark .egs-muted { color:#9ca3af; }
        .egs-list { margin:0; padding-left:1.1rem; }
        .egs-list li { margin:.15rem 0; }
        .egs-warn { color:#b45309; font-weight:600; } .dark .egs-warn { color:#fbbf24; }
        .egs-ok { color:#15803d; } .dark .egs-ok { color:#4ade80; }
        .egs a { color:#2563eb; text-decoration:underline; } .dark .egs a { color:#60a5fa; }
    </style>
    <x-filament::section heading="ΕΡΓΑΝΗ" icon="heroicon-o-building-office-2">
        <div class="egs">
            <div class="egs-row">
                @if ($production)
                    <span class="egs-pill egs-prod">Παραγωγή</span>
                @else
                    <span class="egs-pill egs-trial">ΔΟΚΙΜΑΣΤΙΚΟ — τίποτα δεν πάει στο πραγματικό ΕΡΓΑΝΗ</span>
                @endif
                <span class="egs-muted">Αυτόματη δήλωση: {{ $auto ? implode(', ', $auto) : 'καμία' }}</span>
                <span class="egs-muted">· Τελευταία επιτυχία: {{ $last ?? '—' }}</span>
                <span class="egs-muted">· Υπόχρεη κάρτας:
                    {{ $cardSector === null ? 'δεν ελέγχθηκε ακόμη' : ($cardSector ? 'ΝΑΙ' : 'όχι') }}@if ($cardCheckedAt) ({{ $cardCheckedAt->format('d/m') }})@endif
                </span>
            </div>

            @if ($attention)
                <div>
                    <span class="egs-warn">Θέλει προσοχή:</span>
                    <ul class="egs-list">
                        @foreach ($attention as $a)
                            <li><a href="{{ $a['url'] }}">{{ $a['text'] }}</a></li>
                        @endforeach
                    </ul>
                </div>
            @else
                <div class="egs-ok">✓ Τίποτα εκκρεμές στο ΕΡΓΑΝΗ.</div>
            @endif

            @if ($overtime)
                <div>
                    <span class="egs-muted">Υπερωρίες:</span>
                    <ul class="egs-list">
                        @foreach ($overtime as $line)
                            <li>{{ $line }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
