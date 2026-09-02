<x-filament-panels::page>
    @php
        $result = $this->getResult();
        $columns = \App\Services\Leads\SalesActivityResult::COLUMNS;
        $totals = $result->totals();
        $operators = $this->getOperatorOptions();
        $tenant = \Filament\Facades\Filament::getTenant();
        $leadUrl = fn ($id) => \App\Filament\Resources\Leads\LeadResource::getUrl('edit', ['record' => $id, 'tenant' => $tenant]);
        $statuses = \App\Enums\LeadStatus::cases();
    @endphp

    {{-- Self-contained styling (no Tailwind utility layer is built — see CLAUDE.md). --}}
    <style>
        .sa-filters { display:grid; grid-template-columns:1fr; gap:1rem; }
        @media (min-width:640px){ .sa-filters{ grid-template-columns:repeat(2,minmax(0,1fr)); } }
        @media (min-width:1024px){ .sa-filters{ grid-template-columns:repeat(4,minmax(0,1fr)); } }
        .sa-field { display:flex; flex-direction:column; gap:.25rem; font-size:.875rem; }
        .sa-field > span { font-weight:500; color:#374151; }
        .dark .sa-field > span { color:#d1d5db; }
        .sa-input { border:1px solid #d1d5db; border-radius:.5rem; padding:.45rem .6rem; background:#fff; color:#111827; width:100%; }
        .dark .sa-input { border-color:#4b5563; background:#1f2937; color:#f3f4f6; }
        .sa-note { font-size:.75rem; color:#6b7280; margin-top:.75rem; }
        .dark .sa-note { color:#9ca3af; }
        .sa-wrap { overflow-x:auto; }
        .sa-table { width:100%; border-collapse:collapse; font-size:.875rem; }
        .sa-table th, .sa-table td { padding:.5rem .75rem .5rem 0; text-align:left; vertical-align:top; }
        .sa-table thead th { color:#6b7280; border-bottom:1px solid #e5e7eb; font-weight:600; white-space:nowrap; }
        .dark .sa-table thead th { color:#9ca3af; border-color:rgba(255,255,255,.12); }
        .sa-table tbody td { border-bottom:1px solid #f3f4f6; }
        .dark .sa-table tbody td { border-color:rgba(255,255,255,.07); }
        .sa-table tfoot td { border-top:2px solid #e5e7eb; padding-top:.5rem; font-weight:600; }
        .dark .sa-table tfoot td { border-color:rgba(255,255,255,.18); }
        .sa-num { text-align:right; white-space:nowrap; font-variant-numeric:tabular-nums; }
        .sa-sub { color:#9ca3af; font-weight:400; }
        .sa-strong { font-weight:600; }
        .sa-muted { color:#9ca3af; }
        .sa-nowrap { white-space:nowrap; }
        .sa-empty { padding:1.5rem; text-align:center; color:#6b7280; }
        .dark .sa-empty { color:#9ca3af; }
        .sa-funnel { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:.75rem; }
        @media (min-width:640px){ .sa-funnel{ grid-template-columns:repeat(4,minmax(0,1fr)); } }
        @media (min-width:1024px){ .sa-funnel{ grid-template-columns:repeat(8,minmax(0,1fr)); } }
        .sa-card { border:1px solid #e5e7eb; border-radius:.75rem; padding:.75rem; }
        .dark .sa-card { border-color:rgba(255,255,255,.1); }
        .sa-card__label { font-size:.75rem; color:#6b7280; }
        .dark .sa-card__label { color:#9ca3af; }
        .sa-card__value { font-size:1.4rem; font-weight:700; line-height:1.2; margin-top:.15rem; }
        .sa-link { color:#2563eb; font-weight:500; }
        .sa-link:hover { text-decoration:underline; }
        .dark .sa-link { color:#60a5fa; }
        .sa-body { color:#4b5563; font-size:.8rem; white-space:pre-line; }
        .dark .sa-body { color:#9ca3af; }
    </style>

    {{-- Φίλτρα --}}
    <x-filament::section>
        <x-slot name="heading">Φίλτρα</x-slot>
        <div class="sa-filters">
            <label class="sa-field">
                <span>Περίοδος</span>
                <select class="sa-input" wire:model.live="period">
                    <option value="today">Σήμερα</option>
                    <option value="week">Τρέχουσα εβδομάδα</option>
                    <option value="last_week">Προηγούμενη εβδομάδα</option>
                    <option value="month">Τρέχων μήνας</option>
                    <option value="last_month">Προηγούμενος μήνας</option>
                    <option value="custom">Προσαρμογή…</option>
                </select>
            </label>
            <label class="sa-field">
                <span>Από</span>
                <input type="date" class="sa-input" wire:model.live="from" />
            </label>
            <label class="sa-field">
                <span>Έως</span>
                <input type="date" class="sa-input" wire:model.live="to" />
            </label>
            <label class="sa-field">
                <span>Χειριστής</span>
                <select class="sa-input" wire:model.live="operator">
                    <option value="">— όλοι —</option>
                    @foreach ($operators as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </select>
            </label>
        </div>
        <p class="sa-note">Περίοδος: {{ $result->periodLabel() }} · read-only — μετράει τις γραμμές του χρονολογίου (ποιος τις έγραψε) και τα leads ανά χειριστή.</p>
    </x-filament::section>

    {{-- Ανά χειριστή --}}
    <x-filament::section>
        <x-slot name="heading">Ανά χειριστή</x-slot>
        <x-slot name="description">Τηλέφωνα / emails / ραντεβού που καταγράφηκαν στην περίοδο, με το «απάντησαν / έγιναν» από κάτω· «Ανοιχτά» = τα leads του τώρα, όχι της περιόδου.</x-slot>
        @if ($result->operators === [])
            <div class="sa-empty">Καμία δραστηριότητα στην περίοδο.</div>
        @else
            <div class="sa-wrap">
                <table class="sa-table">
                    <thead>
                        <tr>
                            <th>Χειριστής</th>
                            <th class="sa-num">Νέα leads</th>
                            <th class="sa-num">Τηλέφωνα <span class="sa-sub">(απάντησαν)</span></th>
                            <th class="sa-num">Emails <span class="sa-sub">(απάντησαν)</span></th>
                            <th class="sa-num">Ραντεβού <span class="sa-sub">(έγιναν)</span></th>
                            <th class="sa-num">Προσφορές</th>
                            <th class="sa-num">Μετατροπές</th>
                            <th class="sa-num">Χάθηκαν</th>
                            <th class="sa-num">Ανοιχτά</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($result->operators as $row)
                            <tr>
                                <td class="sa-nowrap {{ $row->userId === null ? 'sa-muted' : 'sa-strong' }}">{{ $row->name }}</td>
                                <td class="sa-num">{{ $row->get('new_leads') }}</td>
                                <td class="sa-num">{{ $row->get('calls') }} <span class="sa-sub">({{ $row->get('calls_answered') }})</span></td>
                                <td class="sa-num">{{ $row->get('emails') }} <span class="sa-sub">({{ $row->get('emails_replied') }})</span></td>
                                <td class="sa-num">{{ $row->get('meetings') }} <span class="sa-sub">({{ $row->get('meetings_held') }})</span></td>
                                <td class="sa-num">{{ $row->get('quotes') }}</td>
                                <td class="sa-num sa-strong">{{ $row->get('conversions') }}</td>
                                <td class="sa-num">{{ $row->get('lost') }}</td>
                                <td class="sa-num">{{ $row->get('open') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr>
                            <td>Σύνολα</td>
                            <td class="sa-num">{{ $totals['new_leads'] }}</td>
                            <td class="sa-num">{{ $totals['calls'] }} <span class="sa-sub">({{ $totals['calls_answered'] }})</span></td>
                            <td class="sa-num">{{ $totals['emails'] }} <span class="sa-sub">({{ $totals['emails_replied'] }})</span></td>
                            <td class="sa-num">{{ $totals['meetings'] }} <span class="sa-sub">({{ $totals['meetings_held'] }})</span></td>
                            <td class="sa-num">{{ $totals['quotes'] }}</td>
                            <td class="sa-num">{{ $totals['conversions'] }}</td>
                            <td class="sa-num">{{ $totals['lost'] }}</td>
                            <td class="sa-num">{{ $totals['open'] }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        @endif
    </x-filament::section>

    {{-- Funnel --}}
    <x-filament::section>
        <x-slot name="heading">Χοάνη (τώρα)</x-slot>
        <div class="sa-funnel">
            @foreach ($statuses as $status)
                <div class="sa-card">
                    <div class="sa-card__label">{{ $status->getLabel() }}</div>
                    <div class="sa-card__value">{{ $result->funnel[$status->value] ?? 0 }}</div>
                </div>
            @endforeach
        </div>
    </x-filament::section>

    {{-- Ημερολόγιο --}}
    <x-filament::section>
        <x-slot name="heading">Ημερολόγιο</x-slot>
        <x-slot name="description">Κάθε γραμμή χρονολογίου της περιόδου, νεότερη πρώτη{{ $result->logTruncated ? ' (οι '.\App\Services\Leads\SalesActivityReport::LOG_LIMIT.' πιο πρόσφατες — στένεψε την περίοδο ή πάρε το CSV)' : '' }}.</x-slot>
        @if ($result->log->isEmpty())
            <div class="sa-empty">Τίποτα στην περίοδο.</div>
        @else
            <div class="sa-wrap">
                <table class="sa-table">
                    <thead>
                        <tr>
                            <th>Πότε</th>
                            <th>Χειριστής</th>
                            <th>Lead</th>
                            <th>Τι</th>
                            <th>Κείμενο</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($result->log as $row)
                            <tr>
                                <td class="sa-nowrap">{{ $row->happened_at?->format('d/m/Y H:i') }}</td>
                                <td class="sa-nowrap">{{ $row->user?->name ?? 'Σύστημα' }}</td>
                                <td class="sa-nowrap">
                                    @if ($row->lead)
                                        <a href="{{ $leadUrl($row->lead_id) }}" class="sa-link">{{ $row->lead->name }}</a>
                                    @else
                                        <span class="sa-muted">#{{ $row->lead_id }}</span>
                                    @endif
                                </td>
                                <td class="sa-nowrap">
                                    <x-filament::badge :color="$row->type?->getColor() ?? 'gray'" :icon="$row->type?->getIcon()">
                                        {{ $row->type?->getLabel() ?? $row->type }}
                                    </x-filament::badge>
                                    @if ($row->outcomeLabel())
                                        <span class="sa-sub">{{ $row->outcomeLabel() }}</span>
                                    @endif
                                </td>
                                <td class="sa-body">{{ $row->body }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
