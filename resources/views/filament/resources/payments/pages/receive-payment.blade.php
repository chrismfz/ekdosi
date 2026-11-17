<x-filament-panels::page>
    <style>
        .rcv-grid { display: grid; grid-template-columns: minmax(0, 1fr); gap: 1rem; }
        @media (min-width: 1024px) { .rcv-grid { grid-template-columns: minmax(0, 320px) minmax(0, 1fr); } }
        .rcv-card { background: #fff; border: 1px solid rgba(0,0,0,.08); border-radius: .75rem; }
        .dark .rcv-card { background: #18181b; border-color: rgba(255,255,255,.1); }
        .rcv-pad { padding: 1rem 1.25rem; }
        .rcv-list { max-height: 70vh; overflow-y: auto; }
        .rcv-row { display: flex; align-items: center; gap: .75rem; width: 100%; padding: .6rem 1rem; border-top: 1px solid rgba(0,0,0,.05); text-align: left; font-size: .875rem; cursor: pointer; background: transparent; }
        .dark .rcv-row { border-color: rgba(255,255,255,.05); }
        .rcv-row:hover { background: rgba(0,0,0,.03); }
        .dark .rcv-row:hover { background: rgba(255,255,255,.04); }
        .rcv-row.is-active { background: #fffbeb; box-shadow: inset 3px 0 0 #d97706; }
        .dark .rcv-row.is-active { background: rgba(217,119,6,.12); }
        .rcv-muted { color: #6b7280; font-size: .75rem; }
        .dark .rcv-muted { color: #9ca3af; }
        .rcv-label { color: #6b7280; font-size: .75rem; margin-bottom: .25rem; }
        .dark .rcv-label { color: #9ca3af; }
        .rcv-danger { color: #dc2626; } .dark .rcv-danger { color: #f87171; }
        .rcv-success { color: #16a34a; } .dark .rcv-success { color: #4ade80; }
        .rcv-warning { color: #b45309; } .dark .rcv-warning { color: #fbbf24; }
        .rcv-num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .rcv-ellipsis { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .rcv-kpis { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: .75rem; }
        .rcv-kpi { background: #f9fafb; border-radius: .5rem; padding: .75rem; }
        .dark .rcv-kpi { background: rgba(255,255,255,.04); }
        .rcv-kpi-v { font-size: 1.25rem; font-weight: 600; font-variant-numeric: tabular-nums; }
        .rcv-fields { display: grid; grid-template-columns: repeat(1, minmax(0, 1fr)); gap: .75rem; }
        @media (min-width: 768px) { .rcv-fields { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
        .rcv-table { width: 100%; border-collapse: collapse; font-size: .875rem; }
        .rcv-table th { text-align: left; font-weight: 500; color: #6b7280; font-size: .75rem; padding: .4rem .5rem; }
        .rcv-table td { padding: .45rem .5rem; border-top: 1px solid rgba(0,0,0,.06); vertical-align: middle; }
        .dark .rcv-table td { border-color: rgba(255,255,255,.06); }
        .rcv-line-input { width: 7.5rem; text-align: right; }
        .rcv-chip { display: inline-block; font-size: .7rem; padding: .1rem .45rem; border-radius: .375rem; white-space: nowrap; }
        .rcv-chip-ok { background: #f0fdf4; color: #15803d; } .dark .rcv-chip-ok { background: rgba(22,163,74,.15); color: #4ade80; }
        .rcv-chip-part { background: #fffbeb; color: #b45309; } .dark .rcv-chip-part { background: rgba(217,119,6,.15); color: #fbbf24; }
        .rcv-chip-old { background: #fef2f2; color: #b91c1c; } .dark .rcv-chip-old { background: rgba(220,38,38,.15); color: #f87171; }
        .rcv-note { border-radius: .5rem; padding: .6rem .8rem; font-size: .8rem; }
        .rcv-note-info { background: #f0fdf4; color: #15803d; } .dark .rcv-note-info { background: rgba(22,163,74,.12); color: #86efac; }
        .rcv-note-warn { background: #fffbeb; color: #92400e; } .dark .rcv-note-warn { background: rgba(217,119,6,.12); color: #fcd34d; }
        .rcv-foot { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: .75rem; border-top: 1px solid rgba(0,0,0,.08); padding-top: .9rem; }
        .dark .rcv-foot { border-color: rgba(255,255,255,.1); }
        .rcv-row-actions { display: flex; flex-wrap: wrap; gap: .5rem; }
        .rcv-empty { padding: 3rem 1rem; text-align: center; }
    </style>

    @php
        $customers = $this->customerRows();
        $customer = $this->customer();
    @endphp

    <div class="rcv-grid">
        {{-- LEFT: customer picker, debtors first --}}
        <div class="rcv-card" style="overflow: hidden;">
            <div style="padding: .75rem;">
                <x-filament::input.wrapper prefix-icon="heroicon-o-magnifying-glass">
                    <x-filament::input type="search" placeholder="Αναζήτηση πελάτη / ΑΦΜ" wire:model.live.debounce.300ms="search" />
                </x-filament::input.wrapper>
            </div>
            <div class="rcv-muted" style="padding: 0 1rem .5rem;">
                {{ trim($search) === '' ? 'Χρωστάνε — μεγαλύτερη οφειλή πρώτα' : 'Αποτελέσματα αναζήτησης' }}
            </div>
            <div class="rcv-list">
                @forelse ($customers as $c)
                    <button type="button" wire:key="cust-{{ $c['id'] }}" wire:click="selectCustomer({{ $c['id'] }})"
                        class="rcv-row {{ $customerId === $c['id'] ? 'is-active' : '' }}">
                        <div style="flex: 1; min-width: 0;">
                            <div class="rcv-ellipsis">{{ $c['name'] }}</div>
                            @if ($c['afm'])
                                <div class="rcv-muted">ΑΦΜ {{ $c['afm'] }}</div>
                            @endif
                        </div>
                        <div class="rcv-num {{ $c['balance'] > 0.005 ? 'rcv-danger' : ($c['balance'] < -0.005 ? 'rcv-success' : 'rcv-muted') }}">
                            {{ abs($c['balance']) > 0.005 ? $this->money($c['balance']) : '—' }}
                        </div>
                    </button>
                @empty
                    <div class="rcv-muted" style="padding: 1rem;">
                        {{ trim($search) === '' ? 'Κανείς πελάτης δεν χρωστάει.' : 'Δεν βρέθηκε πελάτης.' }}
                    </div>
                @endforelse
                @if ($customerListMore)
                    <div class="rcv-muted" style="padding: .6rem 1rem;">Εμφανίζονται οι πρώτοι {{ count($customers) }} — αναζήτησε για τους υπόλοιπους.</div>
                @endif
            </div>
        </div>

        {{-- RIGHT: the receipt --}}
        <div class="rcv-card rcv-pad">
            @if (! $customer)
                <div class="rcv-empty rcv-muted">
                    <x-filament::icon icon="heroicon-o-banknotes" style="width: 2rem; height: 2rem; margin: 0 auto .5rem;" />
                    Διάλεξε πελάτη από τη λίστα για να δεις το υπόλοιπο και τα ανοιχτά του παραστατικά.
                </div>
            @else
                @php
                    $summary = $this->summary();
                    $rows = $this->openRows();
                    $t = $this->totals();
                    $ledger = $this->ledgerUrl();
                @endphp

                <div class="space-y-4">
                    <div class="flex items-baseline justify-between gap-3">
                        <div class="min-w-0">
                            <div style="font-size: 1.05rem; font-weight: 600;" class="rcv-ellipsis">{{ $customer->name }}</div>
                            <div class="rcv-muted">
                                @if ($customer->afm) ΑΦΜ {{ $customer->afm }} @endif
                                @if ($ledger) · <a href="{{ $ledger }}" class="fi-link" style="text-decoration: underline;">Καρτέλα</a> @endif
                            </div>
                        </div>
                    </div>

                    <div class="rcv-kpis">
                        <div class="rcv-kpi">
                            <div class="rcv-label">Υπόλοιπο</div>
                            <div class="rcv-kpi-v {{ $summary['balance'] > 0.005 ? 'rcv-danger' : 'rcv-success' }}">{{ $this->money($summary['balance']) }}</div>
                        </div>
                        <div class="rcv-kpi">
                            <div class="rcv-label">Ανοιχτά παραστατικά</div>
                            <div class="rcv-kpi-v">{{ count($rows) }}</div>
                        </div>
                        <div class="rcv-kpi">
                            <div class="rcv-label">Διαθέσιμη πίστωση (έναντι)</div>
                            <div class="rcv-kpi-v {{ $summary['credit'] > 0.005 ? 'rcv-success' : 'rcv-muted' }}">{{ $this->money($summary['credit']) }}</div>
                        </div>
                    </div>

                    @if ($summary['credit'] > 0.005 && count($rows) > 0)
                        <div class="rcv-note rcv-note-info flex items-center justify-between gap-3">
                            <span>Ο πελάτης έχει {{ $this->money($summary['credit']) }} έναντι λογαριασμού που δεν έχει αντιστοιχιστεί σε παραστατικό.</span>
                            <x-filament::button size="xs" color="success" wire:click="applyCreditFifo"
                                wire:confirm="Να εφαρμοστεί η διαθέσιμη πίστωση στα ανοιχτά παραστατικά, από το παλαιότερο;">
                                Συμψηφισμός πίστωσης
                            </x-filament::button>
                        </div>
                    @endif

                    <div class="rcv-fields">
                        <div>
                            <div class="rcv-label">Ποσό είσπραξης (€)</div>
                            <x-filament::input.wrapper>
                                <x-filament::input type="text" inputmode="decimal" wire:model.live.debounce.400ms="amount" placeholder="π.χ. 430,00" />
                            </x-filament::input.wrapper>
                        </div>
                        <div>
                            <div class="rcv-label">Τρόπος πληρωμής</div>
                            <x-filament::input.wrapper>
                                <x-filament::input.select wire:model="paymentMethodId">
                                    <option value="">—</option>
                                    @foreach ($this->paymentMethodOptions() as $id => $label)
                                        <option value="{{ $id }}">{{ $label }}</option>
                                    @endforeach
                                </x-filament::input.select>
                            </x-filament::input.wrapper>
                        </div>
                        <div>
                            <div class="rcv-label">Ημερομηνία</div>
                            <x-filament::input.wrapper>
                                <x-filament::input type="date" wire:model="payDate" />
                            </x-filament::input.wrapper>
                        </div>
                        @if ($this->bankAccountOptions() !== [])
                            <div>
                                <div class="rcv-label">Τραπεζικός λογαριασμός</div>
                                <x-filament::input.wrapper>
                                    <x-filament::input.select wire:model="bankAccountId">
                                        <option value="">—</option>
                                        @foreach ($this->bankAccountOptions() as $id => $label)
                                            <option value="{{ $id }}">{{ $label }}</option>
                                        @endforeach
                                    </x-filament::input.select>
                                </x-filament::input.wrapper>
                            </div>
                        @endif
                        <div>
                            <div class="rcv-label">Κωδικός συναλλαγής</div>
                            <x-filament::input.wrapper>
                                <x-filament::input type="text" maxlength="100" wire:model="transactionId" placeholder="προαιρετικό" />
                            </x-filament::input.wrapper>
                        </div>
                        <div>
                            <div class="rcv-label">Σημειώσεις</div>
                            <x-filament::input.wrapper>
                                <x-filament::input type="text" wire:model="notes" placeholder="προαιρετικό" />
                            </x-filament::input.wrapper>
                        </div>
                    </div>

                    @if (count($rows) > 0)
                        <div class="rcv-row-actions">
                            <x-filament::button size="xs" color="gray" wire:click="payAll">Εξόφληση όλων</x-filament::button>
                            <x-filament::button size="xs" color="gray" wire:click="payOnly({{ array_key_first($rows) }})">Μόνο το παλαιότερο</x-filament::button>
                            <x-filament::button size="xs" color="gray" wire:click="redistribute">Ξανά-κατανομή (FIFO)</x-filament::button>
                        </div>

                        <div style="overflow-x: auto;">
                            <table class="rcv-table">
                                <thead>
                                    <tr>
                                        <th style="width: 2rem;"></th>
                                        <th>Παραστατικό</th>
                                        <th>Ημ/νία</th>
                                        <th class="rcv-num">Οφειλή</th>
                                        <th class="rcv-num">Είσπραξη</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($rows as $id => $row)
                                        @php
                                            $on = (bool) ($lines[$id]['on'] ?? false);
                                            $lineAmt = $on ? ($this->parseAmount($lines[$id]['amount'] ?? '') ?? 0) : 0;
                                            $late = $this->overdueDays($row['due_at'] ?? null);
                                        @endphp
                                        <tr wire:key="inv-{{ $id }}">
                                            <td><x-filament::input.checkbox wire:model.live="lines.{{ $id }}.on" /></td>
                                            <td>
                                                <a href="{{ \App\Filament\Resources\Invoices\InvoiceResource::getUrl('view', ['record' => $id]) }}" target="_blank" class="fi-link">{{ $row['invcode'] }}</a>
                                            </td>
                                            <td class="rcv-muted">
                                                {{ $row['issued_at'] ? \Carbon\Carbon::parse($row['issued_at'])->format('d/m/Y') : '—' }}
                                                @if ($late !== null)
                                                    <span class="rcv-chip rcv-chip-old" title="Ημέρες μετά τη λήξη">ληξ. {{ $late }} ημ.</span>
                                                @endif
                                            </td>
                                            <td class="rcv-num">{{ $this->money($row['balance']) }}</td>
                                            <td class="rcv-num">
                                                <x-filament::input.wrapper :disabled="! $on" style="display: inline-flex;">
                                                    <x-filament::input type="text" inputmode="decimal" class="rcv-line-input" :disabled="! $on"
                                                        wire:model.live.debounce.400ms="lines.{{ $id }}.amount" />
                                                </x-filament::input.wrapper>
                                            </td>
                                            <td>
                                                @if ($lineAmt > $row['balance'] + 0.005)
                                                    <span class="rcv-chip rcv-chip-old">πάνω από την οφειλή</span>
                                                @elseif ($lineAmt > 0.005 && $lineAmt >= $row['balance'] - 0.005)
                                                    <span class="rcv-chip rcv-chip-ok">κλείνει</span>
                                                @elseif ($lineAmt > 0.005)
                                                    <span class="rcv-chip rcv-chip-part">μερική</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @else
                        <div class="rcv-muted">Ο πελάτης δεν έχει ανοιχτά παραστατικά — η είσπραξη θα καταχωριστεί έναντι λογαριασμού (πίστωση).</div>
                    @endif

                    @if ($t['toInvoices'] > $t['amount'] + 0.005)
                        <div class="rcv-note rcv-note-warn">Η κατανομή στα παραστατικά ({{ $this->money($t['toInvoices']) }}) ξεπερνά το ποσό της είσπραξης ({{ $this->money($t['amount']) }}).</div>
                    @elseif ($t['onAccount'] > 0.005)
                        <div class="rcv-note rcv-note-warn">{{ $this->money($t['onAccount']) }} δεν πάνε σε παραστατικό → θα μείνουν έναντι λογαριασμού (πίστωση πελάτη).</div>
                    @endif

                    <div class="rcv-foot">
                        <div class="rcv-muted" style="font-size: .85rem;">
                            Στα παραστατικά: <strong>{{ $this->money($t['toInvoices']) }}</strong>
                            · Νέο υπόλοιπο: <strong class="{{ $t['newBalance'] > 0.005 ? 'rcv-danger' : 'rcv-success' }}">{{ $this->money($t['newBalance']) }}</strong>
                        </div>
                        <x-filament::button icon="heroicon-o-check" wire:click="save" wire:loading.attr="disabled" wire:target="save"
                            :wire:confirm="$t['onAccount'] > 0.005 ? $this->money($t['onAccount']).' θα καταχωριστούν ως πίστωση/προκαταβολή του πελάτη (έναντι λογαριασμού). Συνέχεια;' : null">
                            Καταχώριση είσπραξης
                        </x-filament::button>
                    </div>
                </div>
            @endif
        </div>
    </div>
</x-filament-panels::page>
