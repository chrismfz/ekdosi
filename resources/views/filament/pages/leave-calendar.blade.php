<x-filament-panels::page>
    @php
        $dayNames = ['Κυ', 'Δε', 'Τρ', 'Τε', 'Πε', 'Πα', 'Σα'];
        $holidays = collect($days)->filter(fn ($d) => $d['holiday'] !== null);
    @endphp

    {{-- Self-contained styling (the panel ships no Tailwind utility layer — see CLAUDE.md). --}}
    <style>
        .lvc-bar { display:flex; flex-wrap:wrap; gap:.4rem; align-items:center; }
        .lvc-btn { border:1px solid #d1d5db; border-radius:.5rem; padding:.4rem .7rem; background:#fff; color:#111827; font-size:.85rem; cursor:pointer; }
        .dark .lvc-btn { border-color:#4b5563; background:#1f2937; color:#f3f4f6; }
        .lvc-btn:hover { background:#f3f4f6; } .dark .lvc-btn:hover { background:#374151; }
        .lvc-title { font-size:1.1rem; font-weight:600; min-width:11rem; text-align:center; text-transform:capitalize; }
        .lvc-scroll { overflow-x:auto; margin-top:1rem; }
        .lvc-table { border-collapse:separate; border-spacing:2px; font-size:.72rem; }
        .lvc-table th, .lvc-table td { text-align:center; min-width:1.9rem; height:1.9rem; padding:0 .15rem; border-radius:.3rem; }
        .lvc-table th.lvc-name, .lvc-table td.lvc-name { text-align:left; min-width:11rem; padding:0 .5rem; position:sticky; left:0; background:#fff; z-index:1; white-space:nowrap; }
        .dark .lvc-table th.lvc-name, .dark .lvc-table td.lvc-name { background:#18181b; }
        .lvc-head { font-weight:600; color:#6b7280; line-height:1.1; }
        .dark .lvc-head { color:#9ca3af; }
        .lvc-cell { background:#f9fafb; }
        .dark .lvc-cell { background:rgba(255,255,255,.03); }
        .lvc-off { background:#e5e7eb; } .dark .lvc-off { background:rgba(255,255,255,.1); }
        .lvc-hol { background:#fde68a; } .dark .lvc-hol { background:rgba(251,191,36,.3); }
        .lvc-today { box-shadow:inset 0 0 0 2px #2563eb; } .dark .lvc-today { box-shadow:inset 0 0 0 2px #60a5fa; }
        .lvc-success { background:#bbf7d0; color:#14532d; font-weight:600; } .dark .lvc-success { background:rgba(74,222,128,.3); color:#bbf7d0; }
        .lvc-danger { background:#fecaca; color:#7f1d1d; font-weight:600; } .dark .lvc-danger { background:rgba(248,113,113,.3); color:#fecaca; }
        .lvc-info { background:#bfdbfe; color:#1e3a8a; font-weight:600; } .dark .lvc-info { background:rgba(96,165,250,.3); color:#bfdbfe; }
        .lvc-gray { background:#d1d5db; color:#1f2937; font-weight:600; } .dark .lvc-gray { background:rgba(156,163,175,.35); color:#e5e7eb; }
        .lvc-pending { background:repeating-linear-gradient(45deg,#fef3c7,#fef3c7 4px,#fde68a 4px,#fde68a 8px); color:#78350f; font-weight:600; }
        .dark .lvc-pending { background:repeating-linear-gradient(45deg,rgba(251,191,36,.15),rgba(251,191,36,.15) 4px,rgba(251,191,36,.3) 4px,rgba(251,191,36,.3) 8px); color:#fde68a; }
        .lvc-sub { font-size:.7rem; color:#6b7280; } .dark .lvc-sub { color:#9ca3af; }
        .lvc-legend { display:flex; flex-wrap:wrap; gap:.75rem; margin-top:.75rem; font-size:.75rem; align-items:center; }
        .lvc-legend span.lvc-sw { display:inline-block; width:1rem; height:1rem; border-radius:.25rem; vertical-align:middle; margin-right:.3rem; }
        .lvc-note { font-size:.75rem; color:#6b7280; margin-top:.5rem; } .dark .lvc-note { color:#9ca3af; }
    </style>

    <x-filament::section>
        <div class="lvc-bar">
            <button type="button" class="lvc-btn" wire:click="previousMonth" title="Προηγούμενος μήνας">‹</button>
            <div class="lvc-title">{{ $this->monthLabel() }}</div>
            <button type="button" class="lvc-btn" wire:click="nextMonth" title="Επόμενος μήνας">›</button>
            <button type="button" class="lvc-btn" wire:click="thisMonth">Σήμερα</button>
        </div>

        <div class="lvc-scroll">
            <table class="lvc-table">
                <thead>
                    <tr>
                        <th class="lvc-name lvc-head">Εργαζόμενος</th>
                        @foreach ($days as $d)
                            <th class="lvc-head {{ $d['holiday'] ? 'lvc-hol' : ($d['weekend'] ? 'lvc-off' : '') }} {{ $d['today'] ? 'lvc-today' : '' }}"
                                title="{{ $d['date']->format('d/m/Y') }}{{ $d['holiday'] ? ' — '.$d['holiday'] : '' }}">
                                {{ $dayNames[$d['date']->dayOfWeek] }}<br>{{ $d['date']->day }}
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        <tr wire:key="lvc-{{ $row['id'] }}">
                            <td class="lvc-name">
                                {{ $row['name'] }}
                                @if ($row['remaining'] !== null)
                                    <div class="lvc-sub">Υπόλοιπο κανονικής: {{ $row['remaining'] }} / {{ $row['entitlement'] }}</div>
                                @endif
                            </td>
                            @foreach ($days as $d)
                                @php($cell = $row['cells'][$d['key']] ?? null)
                                <td class="{{ $cell ? $cell['class'] : ($d['holiday'] ? 'lvc-hol' : ($d['weekend'] ? 'lvc-off' : 'lvc-cell')) }} {{ $d['today'] ? 'lvc-today' : '' }}"
                                    title="{{ $cell ? $cell['title'] : ($d['holiday'] ?? '') }}">
                                    @if ($cell && ! $d['weekend'] && ! $d['holiday'])
                                        {{ mb_substr($cell['label'], 0, 3) }}
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @empty
                        <tr><td class="lvc-name" colspan="{{ count($days) + 1 }}">Δεν υπάρχουν εργαζόμενοι — πρόσθεσέ τους στο «Προσωπικό → Εργαζόμενοι».</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="lvc-legend">
            <span><span class="lvc-sw lvc-success"></span>Κανονική</span>
            <span><span class="lvc-sw lvc-danger"></span>Ασθένεια</span>
            <span><span class="lvc-sw lvc-info"></span>Άλλη άδεια</span>
            <span><span class="lvc-sw lvc-pending"></span>Σε αναμονή</span>
            <span><span class="lvc-sw lvc-hol"></span>Αργία</span>
            <span><span class="lvc-sw lvc-off"></span>Σαββατοκύριακο</span>
        </div>

        @if ($holidays->isNotEmpty())
            <p class="lvc-note">
                Αργίες μήνα:
                {{ $holidays->map(fn ($d) => $d['date']->format('d/m').' '.$d['holiday'])->implode(' · ') }}
            </p>
        @endif
        <p class="lvc-note">Περάστε τον δείκτη πάνω από ένα κελί για λεπτομέρειες. Οι άδειες συναδέλφων εμφανίζονται ως «Άδεια» χωρίς είδος.</p>
    </x-filament::section>
</x-filament-panels::page>
