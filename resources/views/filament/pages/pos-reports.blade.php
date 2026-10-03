<x-filament-panels::page>
    @php
        $r = $this->getResult();
        $t = $r['totals'];
        $detail = $this->getSessionDetail();
        $money = fn ($v) => number_format((float) $v, 2, ',', '.').' €';
        // A refund column: «−12,00 €», but a plain «0,00 €» when there were none (never «−0,00»).
        $minus = fn ($v) => ((float) $v > 0.004 ? '−' : '').number_format((float) $v, 2, ',', '.').' €';
        $signed = fn ($v) => ((float) $v > 0 ? '+' : ((float) $v < 0 ? '−' : '')).number_format(abs((float) $v), 2, ',', '.').' €';
        $tenant = \Filament\Facades\Filament::getTenant();
        $invoiceUrl = fn ($id) => \App\Filament\Resources\Invoices\InvoiceResource::getUrl('view', ['record' => $id, 'tenant' => $tenant]);
        $differenceSum = $r['difference_total'];
    @endphp

    {{-- Self-contained styling (no Tailwind utility layer in the panel — see CLAUDE.md). --}}
    <style>
        .pr-filters { display:grid; grid-template-columns:1fr; gap:1rem; }
        @media (min-width:640px){ .pr-filters{ grid-template-columns:repeat(2,minmax(0,1fr)); } }
        @media (min-width:1024px){ .pr-filters{ grid-template-columns:repeat(4,minmax(0,1fr)); } }
        .pr-field { display:flex; flex-direction:column; gap:.25rem; font-size:.875rem; }
        .pr-field > span { font-weight:500; color:#374151; }
        .dark .pr-field > span { color:#d1d5db; }
        .pr-input { border:1px solid #d1d5db; border-radius:.5rem; padding:.45rem .6rem; background:#fff; color:#111827; width:100%; }
        .dark .pr-input { border-color:#4b5563; background:#1f2937; color:#f3f4f6; }
        .pr-cards { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:.75rem; }
        @media (min-width:768px){ .pr-cards{ grid-template-columns:repeat(4,minmax(0,1fr)); } }
        .pr-card { border:1px solid #e5e7eb; border-radius:.75rem; padding:.75rem; }
        .dark .pr-card { border-color:rgba(255,255,255,.1); }
        .pr-card__label { font-size:.75rem; color:#6b7280; }
        .pr-card__value { font-size:1.3rem; font-weight:700; line-height:1.2; margin-top:.15rem; font-variant-numeric:tabular-nums; }
        .pr-wrap { overflow-x:auto; }
        .pr-table { width:100%; border-collapse:collapse; font-size:.875rem; }
        .pr-table th, .pr-table td { padding:.45rem .75rem .45rem 0; text-align:left; vertical-align:top; }
        .pr-table thead th { color:#6b7280; border-bottom:1px solid #e5e7eb; font-weight:600; white-space:nowrap; }
        .dark .pr-table thead th { color:#9ca3af; border-color:rgba(255,255,255,.12); }
        .pr-table tbody td { border-bottom:1px solid #f3f4f6; }
        .dark .pr-table tbody td { border-color:rgba(255,255,255,.07); }
        .pr-num { text-align:right !important; white-space:nowrap; font-variant-numeric:tabular-nums; }
        .pr-bad { color:#b91c1c; font-weight:600; }
        .pr-ok { color:#15803d; }
        .pr-muted { color:#9ca3af; }
        .pr-click { cursor:pointer; }
        .pr-click:hover td { background:rgba(245,158,11,.07); }
        .pr-detail td { background:rgba(0,0,0,.02); }
        .dark .pr-detail td { background:rgba(255,255,255,.03); }
        .pr-link { color:#2563eb; font-weight:500; }
        .dark .pr-link { color:#60a5fa; }
        .pr-empty { padding:1.25rem; text-align:center; color:#6b7280; }
        .pr-two { display:grid; grid-template-columns:1fr; gap:1rem; }
        @media (min-width:1024px){ .pr-two{ grid-template-columns:repeat(2,minmax(0,1fr)); } }
    </style>

    <x-filament::section>
        <x-slot name="heading">Φίλτρα</x-slot>
        <div class="pr-filters">
            <label class="pr-field">
                <span>Περίοδος</span>
                <select class="pr-input" wire:model.live="period">
                    <option value="today">Σήμερα</option>
                    <option value="week">Τρέχουσα εβδομάδα</option>
                    <option value="last_week">Προηγούμενη εβδομάδα</option>
                    <option value="month">Τρέχων μήνας</option>
                    <option value="last_month">Προηγούμενος μήνας</option>
                    <option value="custom">Προσαρμογή…</option>
                </select>
            </label>
            <label class="pr-field"><span>Από</span><input type="date" class="pr-input" wire:model.live="from"></label>
            <label class="pr-field"><span>Έως</span><input type="date" class="pr-input" wire:model.live="to"></label>
            <label class="pr-field">
                <span>Ταμίας</span>
                <select class="pr-input" wire:model.live="cashier">
                    <option value="">— όλοι —</option>
                    @foreach ($this->getCashierOptions() as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </select>
            </label>
        </div>
    </x-filament::section>

    <div class="pr-cards">
        <div class="pr-card"><div class="pr-card__label">Πωλήσεις ({{ $t['sales_count'] }} αποδείξεις)</div><div class="pr-card__value">{{ $money($t['sales_total']) }}</div></div>
        <div class="pr-card"><div class="pr-card__label">Επιστροφές ({{ $t['refunds_count'] }} · {{ number_format($r['return_rate'], 1, ',', '.') }}%)</div><div class="pr-card__value">{{ $minus($t['refunds_total']) }}</div></div>
        <div class="pr-card"><div class="pr-card__label">Καθαρός τζίρος</div><div class="pr-card__value">{{ $money($t['net_total']) }}</div></div>
        <div class="pr-card"><div class="pr-card__label">Μέσο καλάθι</div><div class="pr-card__value">{{ $money($r['avg_basket']) }}</div></div>
        <div class="pr-card"><div class="pr-card__label">Εκπτώσεις που δόθηκαν</div><div class="pr-card__value">{{ $money($t['discounts']) }}</div></div>
        <div class="pr-card"><div class="pr-card__label">Μετρητά (πωλ. − επιστρ.)</div><div class="pr-card__value">{{ $money($t['cash_sales'] - $t['cash_refunds']) }}</div></div>
        <div class="pr-card"><div class="pr-card__label">Ταμεία ({{ $r['sessions']->count() }})</div><div class="pr-card__value">{{ $r['sessions']->filter->isOpen()->count() }} ανοιχτά</div></div>
        <div class="pr-card"><div class="pr-card__label">Σύνολο διαφορών καταμέτρησης</div><div class="pr-card__value {{ round($differenceSum, 2) != 0 ? 'pr-bad' : 'pr-ok' }}">{{ $signed($differenceSum) }}</div></div>
    </div>

    <x-filament::section>
        <x-slot name="heading">Ανά ταμία</x-slot>
        <x-slot name="description">Τι χτύπησε ο καθένας (αποδείξεις / επιστροφές) και οι διαφορές των ταμείων που έκλεισε ο ίδιος.</x-slot>
        @if ($r['by_cashier'] === [])
            <div class="pr-empty">Καμία κίνηση στην περίοδο.</div>
        @else
            <div class="pr-wrap"><table class="pr-table">
                <thead><tr><th>Ταμίας</th><th class="pr-num">Αποδείξεις</th><th class="pr-num">Πωλήσεις</th><th class="pr-num">Επιστροφές</th><th class="pr-num">Καθαρά</th><th class="pr-num">Εκπτώσεις</th><th class="pr-num">Κλεισίματα</th><th class="pr-num">Διαφορά ταμείου</th></tr></thead>
                <tbody>
                    @foreach ($r['by_cashier'] as $c)
                        <tr>
                            <td>{{ $c['name'] }}</td>
                            <td class="pr-num">{{ $c['sales_count'] }}</td>
                            <td class="pr-num">{{ $money($c['sales']) }}</td>
                            <td class="pr-num">{{ $c['refunds_count'] }} · {{ $minus($c['refunds']) }}</td>
                            <td class="pr-num">{{ $money($c['net']) }}</td>
                            <td class="pr-num">{{ $money($c['discounts']) }}</td>
                            <td class="pr-num">{{ $c['sessions_closed'] }}</td>
                            <td class="pr-num {{ $c['difference'] != 0 ? 'pr-bad' : '' }}">{{ $c['sessions_closed'] ? $signed($c['difference']) : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table></div>
        @endif
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Κλεισίματα ταμείου</x-slot>
        <x-slot name="description">Όσα έκλεισαν στην περίοδο (και όσα είναι ακόμη ανοιχτά). Κλικ σε ένα ταμείο για τα παραστατικά, τις κινήσεις μετρητών και την αναφορά του.</x-slot>
        @if ($r['sessions']->isEmpty())
            <div class="pr-empty">Κανένα ταμείο στην περίοδο.</div>
        @else
            <div class="pr-wrap"><table class="pr-table">
                <thead><tr><th>Ταμείο</th><th>Άνοιγμα</th><th>Κλείσιμο</th><th class="pr-num">Καθαρά</th><th class="pr-num">Αναμενόμενα</th><th class="pr-num">Μετρήθηκαν</th><th class="pr-num">Διαφορά</th></tr></thead>
                <tbody>
                    @foreach ($r['sessions'] as $s)
                        @php($rep = is_array($s->closing_report) ? $s->closing_report : null)
                        @php($diff = $s->isOpen() ? null : round((float) $s->counted_cash - (float) $s->expected_cash, 2))
                        <tr class="pr-click" wire:click="toggleSession({{ $s->id }})" wire:key="s-{{ $s->id }}">
                            <td>{{ $openSession === $s->id ? '▾' : '▸' }} #{{ $s->id }}</td>
                            <td>{{ $s->opened_at?->format('d/m H:i') }} · {{ $s->opener?->name ?? '—' }}</td>
                            <td>@if ($s->isOpen())<span class="pr-ok">ανοιχτό</span>@else{{ $s->closed_at?->format('d/m H:i') }} · {{ $s->closer?->name ?? '—' }}@endif</td>
                            <td class="pr-num">{{ $rep ? $money($rep['net_total'] ?? 0) : '—' }}</td>
                            <td class="pr-num">{{ $s->isOpen() ? '—' : $money($s->expected_cash) }}</td>
                            <td class="pr-num">{{ $s->isOpen() ? '—' : $money($s->counted_cash) }}</td>
                            <td class="pr-num {{ $diff ? 'pr-bad' : 'pr-ok' }}">{{ $diff === null ? '—' : $signed($diff) }}</td>
                        </tr>
                        @if ($detail && $detail['session']->id === $s->id)
                            <tr class="pr-detail" wire:key="d-{{ $s->id }}">
                                <td colspan="7">
                                    <div class="pr-two">
                                        <div>
                                            <strong>Παραστατικά ({{ $detail['docs']->count() }})</strong>
                                            <table class="pr-table">
                                                @forelse ($detail['docs'] as $doc)
                                                    <tr>
                                                        <td><a class="pr-link" href="{{ $invoiceUrl($doc->id) }}" target="_blank">{{ $doc->invcode ?: '#'.$doc->id }}</a>
                                                            @if ($doc->local_status !== 'active') <span class="pr-bad">({{ $doc->local_status === 'draft' ? 'μη εκδοθέν' : 'ακυρωμένο' }})</span>@endif</td>
                                                        <td>{{ $doc->issued_at?->format('H:i') }}</td>
                                                        <td>{{ $doc->posCashier?->name ?? '—' }}</td>
                                                        <td class="pr-num">{{ $doc->invoiceType?->is_credit ? '−' : '' }}{{ $money($doc->payableTotal()) }}</td>
                                                    </tr>
                                                @empty
                                                    <tr><td class="pr-muted">—</td></tr>
                                                @endforelse
                                            </table>
                                        </div>
                                        <div>
                                            <strong>Κινήσεις μετρητών</strong>
                                            <table class="pr-table">
                                                <tr><td>Ρέστα ανοίγματος</td><td class="pr-num">{{ $money($detail['report']['opening_float']) }}</td></tr>
                                                @foreach ($detail['report']['movements'] as $mv)
                                                    <tr><td>{{ $mv['at'] }} {{ $mv['reason'] }} <span class="pr-muted">{{ $mv['user'] }}</span></td><td class="pr-num">{{ $mv['direction'] === 'in' ? '+' : '−' }}{{ $money($mv['amount']) }}</td></tr>
                                                @endforeach
                                                <tr><td><strong>Αναμενόμενα μετρητά</strong></td><td class="pr-num"><strong>{{ $money($detail['report']['expected_cash']) }}</strong></td></tr>
                                            </table>
                                            @if (filled($detail['session']->notes))<p class="pr-muted">Σημειώσεις: {{ $detail['session']->notes }}</p>@endif
                                            <p><a class="pr-link" href="{{ $detail['print_url'] }}" target="_blank">🖨 Αναφορά ταμείου (80mm)</a></p>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        @endif
                    @endforeach
                </tbody>
            </table></div>
        @endif
    </x-filament::section>

    <div class="pr-two">
        <x-filament::section>
            <x-slot name="heading">Ανά ημέρα</x-slot>
            @if ($r['by_day'] === [])
                <div class="pr-empty">—</div>
            @else
                <table class="pr-table">
                    <thead><tr><th>Ημέρα</th><th class="pr-num">Αποδείξεις</th><th class="pr-num">Πωλήσεις</th><th class="pr-num">Επιστροφές</th><th class="pr-num">Καθαρά</th></tr></thead>
                    <tbody>
                        @foreach ($r['by_day'] as $d)
                            <tr><td>{{ \Carbon\Carbon::parse($d['date'])->translatedFormat('D d/m') }}</td><td class="pr-num">{{ $d['sales_count'] }}</td><td class="pr-num">{{ $money($d['sales']) }}</td><td class="pr-num">{{ $minus($d['refunds']) }}</td><td class="pr-num">{{ $money($d['net']) }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Ανά τρόπο πληρωμής & ΦΠΑ</x-slot>
            @if ($t['by_method'] === [])
                <div class="pr-empty">—</div>
            @else
                <table class="pr-table">
                    <thead><tr><th>Τρόπος</th><th class="pr-num">Πωλήσεις</th><th class="pr-num">Επιστροφές</th><th class="pr-num">Καθαρά</th></tr></thead>
                    <tbody>
                        @foreach ($t['by_method'] as $m)
                            <tr><td>{{ $m['method'] }}</td><td class="pr-num">{{ $money($m['sales']) }}</td><td class="pr-num">{{ $minus($m['refunds']) }}</td><td class="pr-num">{{ $money($m['sales'] - $m['refunds']) }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
                <table class="pr-table" style="margin-top:1rem">
                    <thead><tr><th>ΦΠΑ</th><th class="pr-num">Καθαρή αξία</th><th class="pr-num">ΦΠΑ</th><th class="pr-num">Σύνολο</th></tr></thead>
                    <tbody>
                        @foreach ($t['vat'] as $v)
                            <tr><td>{{ rtrim(rtrim(number_format($v['rate'], 2, ',', ''), '0'), ',') }}%</td><td class="pr-num">{{ $money($v['net']) }}</td><td class="pr-num">{{ $money($v['vat']) }}</td><td class="pr-num">{{ $money($v['gross']) }}</td></tr>
                        @endforeach
                        @if ($t['levies'] != 0)
                            <tr><td>Τέλη (π.χ. σακούλα)</td><td></td><td></td><td class="pr-num">{{ $money($t['levies']) }}</td></tr>
                        @endif
                    </tbody>
                </table>
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>
