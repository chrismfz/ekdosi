<x-filament-widgets::widget>
        <style>
            .ssc { list-style:none; margin:0; padding:0; display:grid; gap:.5rem; }
            .ssc li { display:flex; gap:.6rem; align-items:flex-start; font-size:.9rem; }
            .ssc .i { flex:none; width:1.3rem; text-align:center; font-weight:700; }
            .ssc .ok { color:#16a34a; } .dark .ssc .ok { color:#4ade80; }
            .ssc .todo { color:#d97706; } .dark .ssc .todo { color:#fbbf24; }
            .ssc .t { font-weight:600; }
            .ssc .h { color:#6b7280; } .dark .ssc .h { color:#9ca3af; }
            .ssc .opt { font-size:.75rem; color:#6b7280; } .dark .ssc .opt { color:#9ca3af; }
            .ssc a { color:#2563eb; text-decoration:underline; } .dark .ssc a { color:#60a5fa; }
        </style>
        <x-filament::section
            :heading="'Ξεκίνημα Προσωπικού'.($complete ? ' — ✓ όλα έτοιμα' : '')"
            :description="$complete ? 'Όλα τα βήματα έχουν ολοκληρωθεί — ανοίξτε για να τα ξαναδείτε.' : 'Τι λείπει ακόμη για να δουλεύουν άδειες και κάρτα από άκρη σε άκρη.'"
            collapsible
            :collapsed="$complete">
            <ol class="ssc">
                @foreach ($steps as $s)
                    <li>
                        <span class="i {{ $s['done'] ? 'ok' : 'todo' }}">{{ $s['done'] ? '✓' : $loop->iteration }}</span>
                        <span>
                            <span class="t">{{ $s['title'] }}</span>
                            @if ($s['optional'] && ! $s['done']) <span class="opt">(προαιρετικό)</span> @endif
                            @unless ($s['done'])
                                <br><span class="h">{{ $s['hint'] }}</span>
                                @if ($s['url']) <a href="{{ $s['url'] }}">Άνοιγμα →</a> @endif
                            @endunless
                        </span>
                    </li>
                @endforeach
            </ol>
        </x-filament::section>
</x-filament-widgets::widget>
