<x-filament-panels::page>
    @php
        $weeks = $this->weeks();
        $items = $this->getItems();
        $operators = $this->getOperatorOptions();
        $canMove = $this->canMove();
        $monthStart = $this->monthStart();
        $overdueBefore = $this->overdueBeforeGrid();
        $withoutStep = $this->withoutNextStep();
        $tenant = \Filament\Facades\Filament::getTenant();
        $editUrl = fn ($id) => \App\Filament\Resources\Leads\LeadResource::getUrl('edit', ['record' => $id, 'tenant' => $tenant]);
        // Same operator the banners counted, so every count and the list it links
        // to agree (both were operator-blind links under operator-filtered counts).
        $overdueUrl = $this->leadsListUrl('overdue');
        $openUrl = $this->leadsListUrl('open');
        $today = now()->toDateString();
        $dayNames = ['Δευ', 'Τρί', 'Τετ', 'Πέμ', 'Παρ', 'Σάβ', 'Κυρ'];
    @endphp

    {{-- Self-contained styling (no Tailwind utility layer is built — see CLAUDE.md). --}}
    <style>
        .lc-bar { display:flex; flex-wrap:wrap; gap:1rem; align-items:end; justify-content:space-between; }
        .lc-nav { display:flex; gap:.4rem; align-items:center; }
        .lc-btn { border:1px solid #d1d5db; border-radius:.5rem; padding:.4rem .7rem; background:#fff; color:#111827; font-size:.85rem; cursor:pointer; }
        .dark .lc-btn { border-color:#4b5563; background:#1f2937; color:#f3f4f6; }
        .lc-btn:hover { background:#f3f4f6; } .dark .lc-btn:hover { background:#374151; }
        .lc-title { font-size:1.1rem; font-weight:600; min-width:11rem; text-align:center; text-transform:capitalize; }
        .lc-field { display:flex; flex-direction:column; gap:.25rem; font-size:.875rem; min-width:14rem; }
        .lc-field > span { font-weight:500; color:#374151; }
        .dark .lc-field > span { color:#d1d5db; }
        .lc-input { border:1px solid #d1d5db; border-radius:.5rem; padding:.45rem .6rem; background:#fff; color:#111827; width:100%; }
        .dark .lc-input { border-color:#4b5563; background:#1f2937; color:#f3f4f6; }
        .lc-note { font-size:.75rem; color:#6b7280; margin-top:.5rem; }
        .dark .lc-note { color:#9ca3af; }
        .lc-warn { font-size:.8rem; color:#b45309; margin-top:.5rem; } .dark .lc-warn { color:#fbbf24; }
        .lc-warn a { text-decoration:underline; }
        .lc-grid { display:grid; grid-template-columns:repeat(7,minmax(0,1fr)); gap:.35rem; }
        .lc-head { font-size:.75rem; font-weight:600; color:#6b7280; text-align:center; padding:.25rem 0; }
        .dark .lc-head { color:#9ca3af; }
        .lc-day { border:1px solid #e5e7eb; border-radius:.5rem; min-height:6.5rem; padding:.35rem; background:#fff; display:flex; flex-direction:column; gap:.25rem; transition:background .15s, border-color .15s; }
        .dark .lc-day { border-color:rgba(255,255,255,.1); background:#111827; }
        .lc-day.lc-out { background:#f9fafb; opacity:.7; } .dark .lc-day.lc-out { background:rgba(255,255,255,.02); }
        .lc-day.lc-today { border-color:#2563eb; box-shadow:inset 0 0 0 1px #2563eb; } .dark .lc-day.lc-today { border-color:#60a5fa; box-shadow:inset 0 0 0 1px #60a5fa; }
        .lc-day.lc-over { background:#eff6ff; border-color:#2563eb; } .dark .lc-day.lc-over { background:rgba(96,165,250,.12); }
        .lc-num { font-size:.75rem; font-weight:600; color:#374151; display:flex; justify-content:space-between; }
        .dark .lc-num { color:#d1d5db; }
        .lc-num small { font-weight:400; color:#9ca3af; }
        .lc-item { display:block; border-radius:.4rem; padding:.2rem .4rem; font-size:.72rem; line-height:1.25; background:#eef2ff; color:#3730a3; text-decoration:none; }
        .dark .lc-item { background:rgba(129,140,248,.18); color:#c7d2fe; }
        .lc-item[draggable="true"] { cursor:grab; }
        .lc-item.lc-dragging { opacity:.4; }
        .lc-item.lc-overdue { background:#fef2f2; color:#b91c1c; } .dark .lc-item.lc-overdue { background:rgba(248,113,113,.18); color:#fca5a5; }
        .lc-item__time { font-weight:600; }
        .lc-item__who { opacity:.75; }
        @media (max-width:640px){ .lc-grid{ grid-template-columns:repeat(1,minmax(0,1fr)); } .lc-head{ display:none; } .lc-day.lc-out{ display:none; } }
    </style>

    <x-filament::section>
        <div class="lc-bar">
            <div class="lc-nav">
                <button type="button" class="lc-btn" wire:click="previousMonth" title="Προηγούμενος μήνας">‹</button>
                <div class="lc-title">{{ $this->monthLabel() }}</div>
                <button type="button" class="lc-btn" wire:click="nextMonth" title="Επόμενος μήνας">›</button>
                <button type="button" class="lc-btn" wire:click="thisMonth">Σήμερα</button>
            </div>
            <label class="lc-field">
                <span>Χειριστής</span>
                <select class="lc-input" wire:model.live="operator">
                    <option value="">— όλοι —</option>
                    <option value="me">Τα δικά μου</option>
                    @foreach ($operators as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </select>
            </label>
        </div>
        <p class="lc-note">
            Δείχνει <strong>μόνο τα leads που έχουν «επόμενο βήμα»</strong> μέσα στις εμφανιζόμενες εβδομάδες
            (Δευ–Κυρ, μαζί με τις μέρες των γειτονικών μηνών)· κόκκινο = πέρασε.
            Ένα lead χωρίς ημερομηνία επόμενου βήματος δεν εμφανίζεται εδώ (είναι ατζέντα ενεργειών, όχι λίστα leads).
            @if ($canMove) Σύρε ένα lead σε άλλη μέρα για να το μεταθέσεις (κρατά την ώρα). @else Μόνο ανάγνωση. @endif
        </p>
        @if ($overdueBefore > 0)
            <p class="lc-warn">⚠ {{ $overdueBefore }} ληξιπρόθεσμα βήματα πριν από αυτόν τον μήνα — <a href="{{ $overdueUrl }}">δες τα στη λίστα</a>.</p>
        @endif
        @if ($withoutStep > 0)
            <p class="lc-warn">📋 {{ $withoutStep }} ανοιχτά leads <strong>χωρίς επόμενο βήμα</strong> — δεν φαίνονται στο ημερολόγιο· <a href="{{ $openUrl }}">δες τα ανοιχτά στη λίστα</a> και βάλ' τους ημερομηνία.</p>
        @endif
    </x-filament::section>

    {{-- The wrapper swallows a stray drop (the gap between cells) — a dropped <a> must never navigate the tab. --}}
    <div x-data="{ drag: null, over: null }" @dragover.prevent @drop.prevent="drag = null; over = null">
        <div class="lc-grid">
            @foreach ($dayNames as $d)
                <div class="lc-head">{{ $d }}</div>
            @endforeach
            @foreach ($weeks as $week)
                @foreach ($week as $day)
                    @php
                        $key = $day->toDateString();
                        $list = $items[$key] ?? collect();
                        $out = ! $day->isSameMonth($monthStart);
                    @endphp
                    <div class="lc-day {{ $out ? 'lc-out' : '' }} {{ $key === $today ? 'lc-today' : '' }}"
                         :class="{ 'lc-over': over === '{{ $key }}' }"
                         @if ($canMove)
                         @dragover.prevent="over = '{{ $key }}'"
                         @dragleave="over = null"
                         @drop.prevent="over = null; if (drag !== null) { $wire.reschedule(drag, '{{ $key }}') } drag = null"
                         @endif
                    >
                        <div class="lc-num">
                            <span>{{ $day->day }}</span>
                            @if ($list->isNotEmpty()) <small>{{ $list->count() }}</small> @endif
                        </div>
                        @foreach ($list as $lead)
                            <a href="{{ $editUrl($lead->id) }}"
                               class="lc-item {{ $lead->isOverdue() ? 'lc-overdue' : '' }}"
                               wire:key="cal-{{ $lead->id }}"
                               @if ($canMove)
                               draggable="true"
                               @dragstart="drag = {{ $lead->id }}; $event.dataTransfer.setData('text/plain', String(drag)); $event.dataTransfer.effectAllowed = 'move'; $el.classList.add('lc-dragging')"
                               @dragend="$el.classList.remove('lc-dragging'); drag = null; over = null"
                               @endif
                               title="{{ $lead->name }}{{ $lead->assignedTo ? ' · '.$lead->assignedTo->name : '' }}">
                                <span class="lc-item__time">{{ $lead->next_action_at->format('H:i') }}</span>
                                {{ $lead->name }}
                                @if ($lead->assignedTo)<span class="lc-item__who">· {{ $lead->assignedTo->name }}</span>@endif
                            </a>
                        @endforeach
                    </div>
                @endforeach
            @endforeach
        </div>
    </div>
</x-filament-panels::page>
