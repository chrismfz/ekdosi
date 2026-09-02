<x-filament-panels::page>
    @php
        $cards = $this->getCards();
        $columns = \App\Filament\Pages\LeadsBoard::columns();
        $operators = $this->getOperatorOptions();
        $canMove = $this->canMove();
        $tenant = \Filament\Facades\Filament::getTenant();
        $editUrl = fn ($id) => \App\Filament\Resources\Leads\LeadResource::getUrl('edit', ['record' => $id, 'tenant' => $tenant]);
        $notNow = \App\Enums\LeadStatus::NotNow->value;
    @endphp

    {{-- Self-contained styling (no Tailwind utility layer is built — see CLAUDE.md). --}}
    <style>
        .kb-filters { display:flex; flex-wrap:wrap; gap:1rem; align-items:end; }
        .kb-field { display:flex; flex-direction:column; gap:.25rem; font-size:.875rem; min-width:14rem; }
        .kb-field > span { font-weight:500; color:#374151; }
        .dark .kb-field > span { color:#d1d5db; }
        .kb-input { border:1px solid #d1d5db; border-radius:.5rem; padding:.45rem .6rem; background:#fff; color:#111827; width:100%; }
        .dark .kb-input { border-color:#4b5563; background:#1f2937; color:#f3f4f6; }
        .kb-note { font-size:.75rem; color:#6b7280; }
        .dark .kb-note { color:#9ca3af; }
        .kb-board { display:grid; grid-template-columns:repeat(1,minmax(0,1fr)); gap:.75rem; }
        @media (min-width:768px){ .kb-board{ grid-template-columns:repeat(3,minmax(0,1fr)); } }
        @media (min-width:1280px){ .kb-board{ grid-template-columns:repeat(5,minmax(0,1fr)); } }
        .kb-col { border:1px solid #e5e7eb; border-radius:.75rem; background:#f9fafb; display:flex; flex-direction:column; min-height:14rem; transition:background .15s, border-color .15s; }
        .dark .kb-col { border-color:rgba(255,255,255,.1); background:rgba(255,255,255,.03); }
        .kb-col.kb-over { border-color:#2563eb; background:#eff6ff; }
        .dark .kb-col.kb-over { border-color:#60a5fa; background:rgba(96,165,250,.12); }
        .kb-col__head { display:flex; justify-content:space-between; align-items:center; padding:.6rem .75rem; border-bottom:1px solid #e5e7eb; font-weight:600; font-size:.875rem; }
        .dark .kb-col__head { border-color:rgba(255,255,255,.1); }
        .kb-count { font-size:.75rem; font-weight:500; color:#6b7280; background:#e5e7eb; border-radius:9999px; padding:.05rem .5rem; }
        .dark .kb-count { color:#d1d5db; background:rgba(255,255,255,.1); }
        .kb-cards { display:flex; flex-direction:column; gap:.5rem; padding:.6rem; flex:1; }
        .kb-card { display:block; border:1px solid #e5e7eb; border-radius:.6rem; background:#fff; padding:.55rem .65rem; font-size:.8rem; color:inherit; text-decoration:none; }
        .dark .kb-card { border-color:rgba(255,255,255,.12); background:#111827; }
        .kb-card[draggable="true"] { cursor:grab; }
        .kb-card.kb-dragging { opacity:.4; }
        .kb-card__name { font-weight:600; font-size:.85rem; color:#111827; }
        .dark .kb-card__name { color:#f3f4f6; }
        .kb-card__name:hover { text-decoration:underline; }
        .kb-card__row { display:flex; justify-content:space-between; gap:.5rem; margin-top:.25rem; color:#6b7280; }
        .dark .kb-card__row { color:#9ca3af; }
        .kb-due { color:#d97706; } .dark .kb-due { color:#fbbf24; }
        .kb-overdue { color:#dc2626; font-weight:600; } .dark .kb-overdue { color:#f87171; }
        .kb-empty { padding:1rem; text-align:center; font-size:.75rem; color:#9ca3af; }
    </style>

    <x-filament::section>
        <div class="kb-filters">
            <label class="kb-field">
                <span>Χειριστής</span>
                <select class="kb-input" wire:model.live="operator">
                    <option value="">— όλοι —</option>
                    <option value="me">Τα δικά μου</option>
                    @foreach ($operators as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </select>
            </label>
            <p class="kb-note">
                @if ($canMove)
                    Σύρε μια κάρτα σε άλλη στήλη για αλλαγή κατάστασης (γράφεται στο χρονολόγιο). «Όχι τώρα» ζητά ημερομηνία.
                @else
                    Μόνο ανάγνωση — δεν έχεις δικαίωμα αλλαγής leads.
                @endif
                Χαμένα / «Μην ξαναενοχλήσετε» / Πελάτης: από τη σελίδα του lead.
                @if ($this->isCapped()) <strong>Εμφανίζονται οι πρώτες {{ \App\Filament\Pages\LeadsBoard::MAX_CARDS }} κάρτες — φίλτραρε ανά χειριστή.</strong> @endif
            </p>
        </div>
    </x-filament::section>

    <div class="kb-board" x-data="{ drag: null, over: null }">
        @foreach ($columns as $status)
            @php $list = $cards[$status->value]; @endphp
            <div class="kb-col"
                 :class="{ 'kb-over': over === '{{ $status->value }}' }"
                 @if ($canMove)
                 @dragover.prevent="over = '{{ $status->value }}'"
                 @dragleave="over = null"
                 @drop.prevent="over = null; if (drag !== null) { {{ $status->value === $notNow ? "\$wire.mountAction('notNow', { lead: drag })" : "\$wire.moveLead(drag, '".$status->value."')" }} } drag = null"
                 @endif
            >
                <div class="kb-col__head">
                    <span>{{ $status->getLabel() }}</span>
                    <span class="kb-count">{{ $list->count() }}</span>
                </div>
                <div class="kb-cards">
                    @forelse ($list as $lead)
                        <div class="kb-card"
                             wire:key="lead-{{ $lead->id }}"
                             @if ($canMove)
                             draggable="true"
                             @dragstart="drag = {{ $lead->id }}; $el.classList.add('kb-dragging')"
                             @dragend="$el.classList.remove('kb-dragging'); drag = null; over = null"
                             @endif
                        >
                            <a href="{{ $editUrl($lead->id) }}" class="kb-card__name">{{ $lead->name }}</a>
                            @if ($lead->contact_person)
                                <div class="kb-card__row"><span>{{ $lead->contact_person }}</span></div>
                            @endif
                            <div class="kb-card__row">
                                <span>{{ $lead->assignedTo?->name ?? '—' }}</span>
                                @if ($lead->next_action_at)
                                    <span class="{{ $lead->isOverdue() ? 'kb-overdue' : ($lead->next_action_at->isToday() ? 'kb-due' : '') }}"
                                          title="Επόμενο βήμα">{{ $lead->next_action_at->format('d/m H:i') }}</span>
                                @elseif ($lead->last_activity_at)
                                    <span title="Τελευταία επαφή">{{ $lead->last_activity_at->diffForHumans(short: true) }}</span>
                                @endif
                            </div>
                        </div>
                    @empty
                        <div class="kb-empty">—</div>
                    @endforelse
                </div>
            </div>
        @endforeach
    </div>
</x-filament-panels::page>
