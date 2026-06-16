<x-filament-panels::page>
    @php
        $money = fn ($v) => '€ ' . number_format((float) $v, 2, ',', '.');
        $result = $this->getResult();
        $categoryOptions = $this->getCategoryOptions();
    @endphp

    {{-- Self-contained styling: the admin panel ships only Filament's CSS (no
         custom Tailwind theme is built/loaded), so arbitrary utility classes go
         unstyled. We scope the layout here — responsive + dark-mode via .dark —
         so the page looks right with zero build step. Chrome (sections/badges)
         stays Filament-native. --}}
    <style>
        .lb-grid { display:grid; grid-template-columns:1fr; gap:1rem; }
        @media (min-width:640px){ .lb-grid{ grid-template-columns:repeat(3,minmax(0,1fr)); } }
        .lb-filters { display:grid; grid-template-columns:1fr; gap:1rem; }
        @media (min-width:640px){ .lb-filters{ grid-template-columns:repeat(2,minmax(0,1fr)); } }
        @media (min-width:1024px){ .lb-filters{ grid-template-columns:repeat(4,minmax(0,1fr)); } }
        .lb-field { display:flex; flex-direction:column; gap:.25rem; font-size:.875rem; }
        .lb-field > span { font-weight:500; color:#374151; }
        .dark .lb-field > span { color:#d1d5db; }
        .lb-input { border:1px solid #d1d5db; border-radius:.5rem; padding:.45rem .6rem; background:#fff; color:#111827; width:100%; }
        .dark .lb-input { border-color:#4b5563; background:#1f2937; color:#f3f4f6; }
        .lb-card { border:1px solid #e5e7eb; border-radius:.75rem; padding:1rem; }
        .dark .lb-card { border-color:rgba(255,255,255,.1); }
        .lb-card__label { font-size:.8rem; color:#6b7280; }
        .dark .lb-card__label { color:#9ca3af; }
        .lb-card__value { font-size:1.5rem; font-weight:700; line-height:1.2; margin-top:.15rem; }
        .lb-card__hint { font-size:.75rem; color:#6b7280; margin-top:.35rem; }
        .dark .lb-card__hint { color:#9ca3af; }
        .lb-pos { color:#16a34a; } .dark .lb-pos { color:#4ade80; }
        .lb-neg { color:#dc2626; } .dark .lb-neg { color:#f87171; }
        .lb-note { font-size:.75rem; color:#6b7280; margin-top:.75rem; }
        .dark .lb-note { color:#9ca3af; }
        .lb-wrap { overflow-x:auto; }
        .lb-table { width:100%; border-collapse:collapse; font-size:.875rem; }
        .lb-table th, .lb-table td { padding:.5rem 1rem .5rem 0; text-align:left; vertical-align:top; }
        .lb-table thead th { color:#6b7280; border-bottom:1px solid #e5e7eb; font-weight:600; white-space:nowrap; }
        .dark .lb-table thead th { color:#9ca3af; border-color:rgba(255,255,255,.12); }
        .lb-table tbody td { border-bottom:1px solid #f3f4f6; }
        .dark .lb-table tbody td { border-color:rgba(255,255,255,.07); }
        .lb-num { text-align:right; white-space:nowrap; font-variant-numeric:tabular-nums; }
        .lb-nowrap { white-space:nowrap; }
        .lb-strong { font-weight:600; }
        .lb-muted { color:#9ca3af; }
        .lb-sub { font-size:.72rem; color:#9ca3af; }
        .lb-credit { font-size:.72rem; color:#d97706; }
        .dark .lb-credit { color:#fbbf24; }
        .lb-mono { font-family:ui-monospace,SFMono-Regular,Menlo,monospace; font-size:.78rem; }
        .lb-empty { padding:1.5rem; text-align:center; color:#6b7280; }
        .dark .lb-empty { color:#9ca3af; }
    </style>

    {{-- Φίλτρα --}}
    <x-filament::section>
        <x-slot name="heading">Φίλτρα</x-slot>
        <div class="lb-filters">
            <label class="lb-field">
                <span>Από</span>
                <input type="date" class="lb-input" wire:model.live="from" />
            </label>
            <label class="lb-field">
                <span>Έως</span>
                <input type="date" class="lb-input" wire:model.live="to" />
            </label>
            <label class="lb-field">
                <span>Βιβλίο</span>
                <select class="lb-input" wire:model.live="book">
                    <option value="all">Όλα</option>
                    <option value="income">Έσοδα</option>
                    <option value="expense">Έξοδα</option>
                </select>
            </label>
            <label class="lb-field">
                <span>Κατηγορία</span>
                <select class="lb-input" wire:model.live="category">
                    <option value="">— όλες —</option>
                    @foreach ($categoryOptions as $code => $label)
                        <option value="{{ $code }}">{{ $label }}</option>
                    @endforeach
                </select>
            </label>
        </div>
        <p class="lb-note">Περίοδος: {{ $result->periodLabel }} · read-only — η σελίδα δεν τροποποιεί τίποτα.</p>
    </x-filament::section>

    {{-- Σύνολα περιόδου --}}
    <x-filament::section>
        <x-slot name="heading">Σύνολα περιόδου</x-slot>
        <div class="lb-grid">
            <div class="lb-card">
                <div class="lb-card__label">Έσοδα ({{ $result->incomeCount() }})</div>
                <div class="lb-card__value lb-pos">{{ $money($result->incomeNet()) }}</div>
                <div class="lb-card__hint">ΦΠΑ εκροών {{ $money($result->incomeVat()) }} · μικτό {{ $money($result->incomeGross()) }}</div>
            </div>
            <div class="lb-card">
                <div class="lb-card__label">Έξοδα ({{ $result->expenseCount() }})</div>
                <div class="lb-card__value lb-neg">{{ $money($result->expenseNet()) }}</div>
                <div class="lb-card__hint">ΦΠΑ εισροών {{ $money($result->expenseVat()) }} · μικτό {{ $money($result->expenseGross()) }}</div>
            </div>
            <div class="lb-card">
                <div class="lb-card__label">ΦΠΑ εκροών − εισροών</div>
                <div class="lb-card__value {{ $result->vatBalance() > 0 ? 'lb-neg' : 'lb-pos' }}">{{ $money(abs($result->vatBalance())) }}</div>
                <div class="lb-card__hint">{{ $result->vatBalance() > 0 ? 'Προς απόδοση' : 'Πιστωτικό υπόλοιπο' }}</div>
            </div>
        </div>
        <p class="lb-note">
            Τα πιστωτικά εμφανίζονται με αρνητικό πρόσημο και συμψηφίζονται στα σύνολα —
            γι' αυτό τα έσοδα εδώ μπορεί να διαφέρουν από τον πίνακα εργαλείων (που εξαιρεί τα πιστωτικά).
        </p>
    </x-filament::section>

    {{-- Σύνολα ανά κατηγορία --}}
    @foreach (['income' => 'Έσοδα ανά κατηγορία', 'expense' => 'Έξοδα ανά κατηγορία'] as $bk => $heading)
        @php $subtotals = $result->categorySubtotals($bk); @endphp
        @if (! empty($subtotals))
            <x-filament::section collapsible>
                <x-slot name="heading">{{ $heading }}</x-slot>
                <div class="lb-wrap">
                    <table class="lb-table">
                        <thead>
                            <tr>
                                <th>Κατηγορία</th>
                                <th>Λογαριασμός</th>
                                <th class="lb-num">Πλήθος</th>
                                <th class="lb-num">Καθαρό</th>
                                <th class="lb-num">ΦΠΑ</th>
                                <th class="lb-num">Σύνολο</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($subtotals as $row)
                                <tr>
                                    <td>
                                        {{ $row['label'] ?? ($row['code'] ?: '— αταξινόμητο —') }}
                                        @if ($row['code'])<span class="lb-sub">({{ $row['code'] }})</span>@endif
                                    </td>
                                    <td>{{ $row['account'] ?? '—' }}</td>
                                    <td class="lb-num">{{ $row['count'] }}</td>
                                    <td class="lb-num">{{ $money($row['net']) }}</td>
                                    <td class="lb-num">{{ $money($row['vat']) }}</td>
                                    <td class="lb-num lb-strong">{{ $money($row['gross']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-filament::section>
        @endif
    @endforeach

    {{-- Ημερολόγιο --}}
    <x-filament::section>
        <x-slot name="heading">Ημερολόγιο</x-slot>
        <div class="lb-wrap">
            <table class="lb-table">
                <thead>
                    <tr>
                        <th>Ημ/νία</th>
                        <th>Βιβλίο</th>
                        <th>Παραστατικό</th>
                        <th>ΜΑΡΚ</th>
                        <th>myDATA</th>
                        <th>Είδος</th>
                        <th>Αντισυμβαλλόμενος</th>
                        <th>ΑΦΜ</th>
                        <th>Κατηγορία</th>
                        <th>Λογ/σμός</th>
                        <th class="lb-num">Καθαρό</th>
                        <th class="lb-num">ΦΠΑ</th>
                        <th class="lb-num">Σύνολο</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($result->rows as $row)
                        <tr>
                            <td class="lb-nowrap">{{ $row->date->format('d/m/Y') }}</td>
                            <td class="lb-nowrap">
                                <x-filament::badge :color="$row->book === 'income' ? 'success' : 'danger'">
                                    {{ $row->book === 'income' ? 'Έσοδο' : 'Έξοδο' }}
                                </x-filament::badge>
                                @if ($row->isCredit)<div class="lb-credit">πιστωτικό</div>@endif
                            </td>
                            <td class="lb-nowrap lb-strong">{{ $row->doc }}</td>
                            <td class="lb-mono">{{ $row->mark ?? '—' }}</td>
                            <td>
                                @if ($row->mydataState === 'VALID')
                                    <x-filament::badge color="success">VALID</x-filament::badge>
                                @elseif ($row->mydataState === 'CANCELLED')
                                    <x-filament::badge color="danger">CANCELLED</x-filament::badge>
                                @else
                                    <span class="lb-muted">—</span>
                                @endif
                            </td>
                            <td>{{ $row->docType }}</td>
                            <td>{{ $row->counterparty ?? '—' }}</td>
                            <td class="lb-nowrap">{{ $row->afm ?? '—' }}</td>
                            <td>
                                {{ $row->categoryLabel ?? '—' }}
                                @if ($row->categoryCode)<span class="lb-sub">({{ $row->categoryCode }})</span>@endif
                            </td>
                            <td class="lb-nowrap" @if ($row->accountName) title="{{ $row->accountName }}" @endif>{{ $row->accountCode ?? '—' }}</td>
                            <td class="lb-num">{{ $money($row->net) }}</td>
                            <td class="lb-num">{{ $money($row->vat) }}</td>
                            <td class="lb-num lb-strong">{{ $money($row->gross) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="13" class="lb-empty">Καμία εγγραφή στην περίοδο.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
