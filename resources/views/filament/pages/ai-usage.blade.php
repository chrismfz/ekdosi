<x-filament-panels::page>
    @php
        $report = $this->getReport();
        $tok = fn ($v) => number_format((int) $v, 0, ',', '.');
        $usd = fn ($v) => '$' . number_format((float) $v, 4, '.', ',');
        $t = $report['totals'];
        $maxTrend = max(1, ...array_map(fn ($r) => $r['billable'], $report['trend']));
        $statusColor = ['ok' => 'gray', 'warn' => 'warning', 'blocked' => 'danger'];
        $statusLabel = ['ok' => 'εντός', 'warn' => 'κοντά στο όριο', 'blocked' => 'όριο'];
    @endphp

    {{-- Self-contained styling — no Tailwind utility layer is built (see CLAUDE.md). --}}
    <style>
        .aiu-bar { display:flex; flex-wrap:wrap; align-items:flex-end; gap:1rem; justify-content:space-between; }
        .aiu-field { display:flex; flex-direction:column; gap:.25rem; font-size:.875rem; }
        .aiu-field > span { font-weight:500; color:#374151; }
        .dark .aiu-field > span { color:#d1d5db; }
        .aiu-input { border:1px solid #d1d5db; border-radius:.5rem; padding:.45rem .6rem; background:#fff; color:#111827; min-width:12rem; }
        .dark .aiu-input { border-color:#4b5563; background:#1f2937; color:#f3f4f6; }
        .aiu-note { font-size:.75rem; color:#6b7280; }
        .dark .aiu-note { color:#9ca3af; }
        .aiu-cards { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:.75rem; }
        @media (min-width:768px){ .aiu-cards{ grid-template-columns:repeat(4,minmax(0,1fr)); } }
        .aiu-card { border:1px solid #e5e7eb; border-radius:.75rem; padding:.75rem .9rem; }
        .dark .aiu-card { border-color:rgba(255,255,255,.1); }
        .aiu-card__label { font-size:.75rem; color:#6b7280; }
        .dark .aiu-card__label { color:#9ca3af; }
        .aiu-card__value { font-size:1.5rem; font-weight:700; line-height:1.2; margin-top:.15rem; }
        .aiu-wrap { overflow-x:auto; }
        .aiu-table { width:100%; border-collapse:collapse; font-size:.875rem; }
        .aiu-table th, .aiu-table td { padding:.5rem .75rem .5rem 0; text-align:left; vertical-align:top; }
        .aiu-table thead th { color:#6b7280; border-bottom:1px solid #e5e7eb; font-weight:600; white-space:nowrap; }
        .dark .aiu-table thead th { color:#9ca3af; border-color:rgba(255,255,255,.12); }
        .aiu-table tbody td { border-bottom:1px solid #f3f4f6; }
        .dark .aiu-table tbody td { border-color:rgba(255,255,255,.07); }
        .aiu-table tfoot td { border-top:2px solid #e5e7eb; padding-top:.5rem; font-weight:600; }
        .dark .aiu-table tfoot td { border-color:rgba(255,255,255,.18); }
        .aiu-num { text-align:right; white-space:nowrap; font-variant-numeric:tabular-nums; }
        .aiu-strong { font-weight:600; }
        .aiu-muted { color:#9ca3af; }
        .aiu-empty { padding:1.5rem; text-align:center; color:#6b7280; }
        .dark .aiu-empty { color:#9ca3af; }
        .aiu-trend { display:flex; align-items:flex-end; gap:.5rem; height:7rem; }
        .aiu-trend__col { display:flex; flex-direction:column; align-items:center; gap:.35rem; flex:1; min-width:0; }
        .aiu-trend__bar { width:100%; max-width:2.5rem; border-radius:.35rem .35rem 0 0; background:#6366f1; min-height:2px; }
        .dark .aiu-trend__bar { background:#818cf8; }
        .aiu-trend__meta { font-size:.7rem; color:#6b7280; text-align:center; white-space:nowrap; }
        .dark .aiu-trend__meta { color:#9ca3af; }
    </style>

    {{-- Period picker --}}
    <x-filament::section>
        <div class="aiu-bar">
            <label class="aiu-field">
                <span>Μήνας</span>
                <select class="aiu-input" wire:model.live="month">
                    @foreach ($this->getMonthOptions() as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <div class="aiu-note">
                Δεδομένα από το <span class="aiu-strong">ai_usage_log</span> (πηγή αλήθειας για τα όρια). Το κόστος
                είναι <span class="aiu-strong">εκτίμηση σε USD</span> (ανά μοντέλο), για συμφωνία με τον μηνιαίο
                λογαριασμό Anthropic. Cross-tenant προβολή — μόνο για διαχειριστή συστήματος.
            </div>
        </div>
    </x-filament::section>

    {{-- Month summary --}}
    <div class="aiu-cards">
        <x-filament::section>
            <div class="aiu-card__label">Αιτήματα ({{ $report['monthLabel'] }})</div>
            <div class="aiu-card__value">{{ $tok($t['requests']) }}</div>
        </x-filament::section>
        <x-filament::section>
            <div class="aiu-card__label">Χρεώσιμα tokens</div>
            <div class="aiu-card__value">{{ $tok($t['billable']) }}</div>
        </x-filament::section>
        <x-filament::section>
            <div class="aiu-card__label">Cache (read / write)</div>
            <div class="aiu-card__value" style="font-size:1.1rem;">{{ $tok($t['cacheRead']) }} / {{ $tok($t['cacheWrite']) }}</div>
        </x-filament::section>
        <x-filament::section>
            <div class="aiu-card__label">Εκτ. κόστος (USD)</div>
            <div class="aiu-card__value">{{ $usd($t['cost']) }}</div>
        </x-filament::section>
    </div>

    {{-- Per-company --}}
    <x-filament::section>
        <x-slot name="heading">Ανά εταιρεία — {{ $report['monthLabel'] }}</x-slot>
        <x-slot name="description">Ποιος πληρώνει και ποιος πλησιάζει το μηνιαίο όριο tokens.</x-slot>

        @if (count($report['companies']) === 0)
            <div class="aiu-empty">Καμία χρήση AI για τον μήνα.</div>
        @else
            <div class="aiu-wrap">
                <table class="aiu-table">
                    <thead>
                        <tr>
                            <th>Εταιρεία</th>
                            <th class="aiu-num">Αιτήματα</th>
                            <th class="aiu-num">Tokens (in/out)</th>
                            <th class="aiu-num">Χρεώσιμα</th>
                            <th class="aiu-num">Όριο</th>
                            <th class="aiu-num">% ορίου</th>
                            <th class="aiu-num">Κόστος</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($report['companies'] as $r)
                            <tr>
                                <td class="aiu-strong">
                                    {{ $r['name'] }}
                                    @if ($report['isCurrentMonth'] && $r['status'] !== 'ok')
                                        <x-filament::badge :color="$statusColor[$r['status']]">{{ $statusLabel[$r['status']] }}</x-filament::badge>
                                    @endif
                                </td>
                                <td class="aiu-num">{{ $tok($r['requests']) }}</td>
                                <td class="aiu-num">{{ $tok($r['input']) }} / {{ $tok($r['output']) }}</td>
                                <td class="aiu-num aiu-strong">{{ $tok($r['billable']) }}</td>
                                <td class="aiu-num">{{ $r['cap'] !== null ? $tok($r['cap']) : '—' }}</td>
                                <td class="aiu-num">{{ $r['pct'] !== null ? number_format($r['pct'] * 100, 1, ',', '.') . '%' : '—' }}</td>
                                <td class="aiu-num">{{ $usd($r['cost']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr>
                            <td>Σύνολα</td>
                            <td class="aiu-num">{{ $tok($t['requests']) }}</td>
                            <td class="aiu-num">{{ $tok($t['input']) }} / {{ $tok($t['output']) }}</td>
                            <td class="aiu-num">{{ $tok($t['billable']) }}</td>
                            <td class="aiu-num">—</td>
                            <td class="aiu-num">—</td>
                            <td class="aiu-num">{{ $usd($t['cost']) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        @endif
    </x-filament::section>

    {{-- Per-user --}}
    @if (count($report['users']) > 0)
        <x-filament::section>
            <x-slot name="heading">Ανά χρήστη — {{ $report['monthLabel'] }}</x-slot>
            <x-slot name="description">«Ποιος έκαψε το budget» — ανά χειριστή και εταιρεία.</x-slot>
            <div class="aiu-wrap">
                <table class="aiu-table">
                    <thead>
                        <tr>
                            <th>Χρήστης</th>
                            <th>Εταιρεία</th>
                            <th class="aiu-num">Αιτήματα</th>
                            <th class="aiu-num">Χρεώσιμα tokens</th>
                            <th class="aiu-num">Κόστος</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($report['users'] as $r)
                            <tr>
                                <td class="aiu-strong">{{ $r['name'] }}</td>
                                <td class="aiu-muted">{{ $r['company'] }}</td>
                                <td class="aiu-num">{{ $tok($r['requests']) }}</td>
                                <td class="aiu-num">{{ $tok($r['billable']) }}</td>
                                <td class="aiu-num">{{ $usd($r['cost']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    @endif

    {{-- Trend --}}
    <x-filament::section>
        <x-slot name="heading">Μηνιαία τάση (χρεώσιμα tokens)</x-slot>
        <x-slot name="description">Οι τελευταίοι μήνες — για να φαίνεται η κατεύθυνση της κατανάλωσης.</x-slot>
        <div class="aiu-trend">
            @foreach ($report['trend'] as $b)
                <div class="aiu-trend__col">
                    <div class="aiu-trend__bar" style="height: {{ max(2, (int) round($b['billable'] / $maxTrend * 90)) }}px;" title="{{ $tok($b['billable']) }} tokens · {{ $usd($b['cost']) }}"></div>
                    <div class="aiu-trend__meta">{{ $b['label'] }}<br>{{ $usd($b['cost']) }}</div>
                </div>
            @endforeach
        </div>
    </x-filament::section>
</x-filament-panels::page>
