<x-filament-panels::page>
    @php
        /** @var \App\Services\CustomerLedger\CustomerLedgerResult $ledger */
        $ledger = $this->ledger;
        $stats = $ledger->stats;
        $aging = $ledger->aging;
        $cust = $this->record;
        $hasWhmcs = $cust->whmcs_client_id !== null && ($cust->company?->hasWhmcsIntegration() ?? false);

        $fmtMoney = fn ($v) => number_format((float) $v, 2, ',', '.') . ' €';
        $balanceClass = $stats['balance'] > 0 ? 'text-danger-600 dark:text-danger-400' : 'text-success-600 dark:text-success-400';
    @endphp

    {{-- ============= Section 1: Header card ============= --}}
    <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 p-5 shadow-sm">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h2 class="text-2xl font-bold">{{ $cust->name }}</h2>
                <div class="text-sm text-gray-500 dark:text-gray-400 mt-1 space-x-3">
                    @if ($cust->afm)
                        <span><strong>ΑΦΜ:</strong> {{ $cust->afm }}</span>
                    @endif
                    @if ($cust->tax_office)
                        <span><strong>ΔΟΥ:</strong> {{ $cust->tax_office }}</span>
                    @endif
                    @if ($cust->occupation)
                        <span><strong>Δραστηριότητα:</strong> {{ $cust->occupation }}</span>
                    @endif
                </div>
                <div class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                    @if ($cust->address1)
                        {{ $cust->address1 }}{{ $cust->city ? ', ' . $cust->city : '' }}{{ $cust->postcode ? ' ' . $cust->postcode : '' }}
                    @endif
                </div>
                @if ($cust->whmcs_client_id)
                    <div class="text-xs text-gray-500 dark:text-gray-400 mt-2">
                        <span class="inline-block px-2 py-0.5 rounded bg-info-100 dark:bg-info-950/40 text-info-700 dark:text-info-300">
                            Linked to WHMCS client #{{ $cust->whmcs_client_id }}
                        </span>
                    </div>
                @endif
            </div>
            <div class="text-right">
                <div class="text-xs text-gray-500 uppercase">Υπόλοιπο</div>
                <div class="text-4xl font-bold {{ $balanceClass }}">{{ $fmtMoney($stats['balance']) }}</div>
                @if ($stats['oldest_unpaid_days'] !== null)
                    <div class="text-xs text-gray-500 mt-1">
                        παλαιότερο ανεξόφλητο: <strong>{{ $stats['oldest_unpaid_days'] }} ημ.</strong>
                    </div>
                @endif
            </div>
        </div>
    </div>

    @if (! $ledger->hasAnyActivity())
        <div class="mt-6 rounded-xl border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-900 p-8 text-center text-gray-500">
            Αυτός ο πελάτης δεν έχει κινήσεις ακόμη.
        </div>
    @else

    {{-- ============= Section 2: Quick stats ============= --}}
    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3 mt-6">
        <div class="rounded-lg bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 p-3">
            <div class="text-xs text-gray-500 uppercase">YTD καθαρή</div>
            <div class="text-lg font-semibold">{{ $fmtMoney($stats['ytd_net']) }}</div>
        </div>
        <div class="rounded-lg bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 p-3">
            <div class="text-xs text-gray-500 uppercase">YTD αξία (με ΦΠΑ)</div>
            <div class="text-lg font-semibold">{{ $fmtMoney($stats['ytd_gross']) }}</div>
        </div>
        <div class="rounded-lg bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 p-3">
            <div class="text-xs text-gray-500 uppercase">YTD πληρωμές</div>
            <div class="text-lg font-semibold text-success-700 dark:text-success-300">{{ $fmtMoney($stats['ytd_paid']) }}</div>
        </div>
        <div class="rounded-lg bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 p-3">
            <div class="text-xs text-gray-500 uppercase">Σύνολο τιμολογίων</div>
            <div class="text-lg font-semibold">{{ $stats['total_invoices_lifetime'] }}</div>
        </div>
        <div class="rounded-lg bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 p-3">
            <div class="text-xs text-gray-500 uppercase">Τελευταία κίνηση</div>
            <div class="text-lg font-semibold">
                {{ $stats['last_activity_at'] ? \Illuminate\Support\Carbon::parse($stats['last_activity_at'])->format('Y-m-d') : '—' }}
            </div>
        </div>
        <div class="rounded-lg bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 p-3">
            <div class="text-xs text-gray-500 uppercase">Υπόλοιπο</div>
            <div class="text-lg font-semibold {{ $balanceClass }}">{{ $fmtMoney($stats['balance']) }}</div>
        </div>
    </div>

    {{-- ============= Section 3: Aging buckets ============= --}}
    @if ($stats['balance'] > 0)
        <div class="mt-6 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 p-4">
            <div class="text-sm font-semibold mb-3">Πελάτες κατά ηλικία (ανεξόφλητα)</div>
            <div class="grid grid-cols-4 gap-3">
                <div class="rounded bg-success-50 dark:bg-success-950/40 p-3">
                    <div class="text-xs text-success-700 dark:text-success-300 uppercase">0-30 ημ.</div>
                    <div class="text-lg font-semibold text-success-700 dark:text-success-300">{{ $fmtMoney($aging['bucket_0_30']) }}</div>
                </div>
                <div class="rounded bg-warning-50 dark:bg-warning-950/40 p-3">
                    <div class="text-xs text-warning-700 dark:text-warning-300 uppercase">31-60 ημ.</div>
                    <div class="text-lg font-semibold text-warning-700 dark:text-warning-300">{{ $fmtMoney($aging['bucket_31_60']) }}</div>
                </div>
                <div class="rounded bg-danger-50/60 dark:bg-danger-950/30 p-3">
                    <div class="text-xs text-danger-700 dark:text-danger-300 uppercase">61-90 ημ.</div>
                    <div class="text-lg font-semibold text-danger-700 dark:text-danger-300">{{ $fmtMoney($aging['bucket_61_90']) }}</div>
                </div>
                <div class="rounded bg-danger-100 dark:bg-danger-950/60 p-3">
                    <div class="text-xs text-danger-700 dark:text-danger-300 uppercase">90+ ημ.</div>
                    <div class="text-lg font-semibold text-danger-700 dark:text-danger-300">{{ $fmtMoney($aging['bucket_90_plus']) }}</div>
                </div>
            </div>
        </div>
    @endif

    {{-- ============= Section 4: Yearly breakdown ============= --}}
    @if (count($ledger->yearly) > 0)
        <div class="mt-6 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 p-4">
            <div class="text-sm font-semibold mb-3">Ετήσια ανάλυση</div>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="text-xs text-gray-500 uppercase">
                        <tr>
                            <th class="px-3 py-2 text-left">Έτος</th>
                            <th class="px-3 py-2 text-right">Τιμολόγια</th>
                            <th class="px-3 py-2 text-right">Καθαρή αξία</th>
                            <th class="px-3 py-2 text-right">Με ΦΠΑ</th>
                            <th class="px-3 py-2 text-right">Πληρωμές</th>
                            <th class="px-3 py-2 text-right">Υπόλοιπο τέλους έτους</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                        @foreach ($ledger->yearly as $row)
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/50">
                                <td class="px-3 py-2 font-mono">{{ $row['year'] }}</td>
                                <td class="px-3 py-2 text-right">{{ $row['invoice_count'] }}</td>
                                <td class="px-3 py-2 text-right font-mono">{{ $fmtMoney($row['net']) }}</td>
                                <td class="px-3 py-2 text-right font-mono">{{ $fmtMoney($row['gross']) }}</td>
                                <td class="px-3 py-2 text-right font-mono text-success-700 dark:text-success-300">{{ $fmtMoney($row['paid']) }}</td>
                                <td class="px-3 py-2 text-right font-mono {{ $row['year_end_balance'] > 0 ? 'text-danger-600' : 'text-gray-600' }}">{{ $fmtMoney($row['year_end_balance']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    {{-- ============= Section 5: Chronological ledger (the main one) ============= --}}
    <div class="mt-6 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 p-4">
        <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
            <div class="text-sm font-semibold">Καρτέλα κινήσεων</div>

            {{-- Filters --}}
            <div class="flex flex-wrap gap-2 items-center text-sm">
                <select wire:model.live="filterYear" class="rounded border-gray-300 dark:border-gray-700 dark:bg-gray-900 text-sm">
                    <option value="">Όλα τα έτη</option>
                    @foreach ($availableYears as $y)
                        <option value="{{ $y }}">{{ $y }}</option>
                    @endforeach
                </select>
                <select wire:model.live="filterInvoiceTypeId" class="rounded border-gray-300 dark:border-gray-700 dark:bg-gray-900 text-sm">
                    <option value="">Όλοι οι τύποι</option>
                    @foreach ($availableInvoiceTypes as $it)
                        <option value="{{ $it['id'] }}">{{ $it['code'] }}</option>
                    @endforeach
                </select>
                <select wire:model.live="filterPaidStatus" class="rounded border-gray-300 dark:border-gray-700 dark:bg-gray-900 text-sm">
                    <option value="">Όλα</option>
                    <option value="paid">Εξοφλημένα</option>
                    <option value="unpaid">Ανεξόφλητα</option>
                </select>
                @if ($filterYear || $filterInvoiceTypeId || $filterPaidStatus)
                    <button type="button" wire:click="resetFilters" class="text-xs text-primary-600 hover:underline">
                        Καθαρισμός
                    </button>
                @endif
            </div>
        </div>

        @if (count($ledger->ledger) === 0)
            <div class="text-center text-gray-500 py-8 text-sm">Δεν βρέθηκαν κινήσεις με τα επιλεγμένα φίλτρα.</div>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="text-xs text-gray-500 uppercase">
                        <tr>
                            <th class="px-3 py-2 text-left">Ημερομηνία</th>
                            <th class="px-3 py-2 text-left">Τύπος</th>
                            <th class="px-3 py-2 text-left">Αναφορά</th>
                            <th class="px-3 py-2 text-right">Χρέωση</th>
                            <th class="px-3 py-2 text-right">Πίστωση</th>
                            <th class="px-3 py-2 text-right">Υπόλοιπο</th>
                            <th class="px-3 py-2 text-left">myDATA</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                        @foreach ($ledger->ledger as $row)
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/50">
                                <td class="px-3 py-2 font-mono whitespace-nowrap">{{ $row['date'] }}</td>
                                <td class="px-3 py-2">
                                    @if ($row['type'] === 'invoice')
                                        <span class="text-xs px-2 py-0.5 rounded bg-info-100 text-info-700 dark:bg-info-950/40 dark:text-info-300">
                                            {{ $row['invoice_type_code'] ?? 'Τιμολόγιο' }}
                                        </span>
                                    @else
                                        <span class="text-xs px-2 py-0.5 rounded bg-success-100 text-success-700 dark:bg-success-950/40 dark:text-success-300">
                                            Πληρωμή
                                        </span>
                                    @endif
                                </td>
                                <td class="px-3 py-2">
                                    @if ($row['type'] === 'invoice' && $row['invoice_id'])
                                        <a href="{{ route('filament.admin.resources.invoices.view', ['tenant' => $cust->company->slug, 'record' => $row['invoice_id']]) }}"
                                           class="text-primary-600 hover:underline font-mono">
                                            {{ $row['reference'] }}
                                        </a>
                                    @else
                                        <span class="font-mono">{{ $row['reference'] }}</span>
                                    @endif
                                </td>
                                <td class="px-3 py-2 text-right font-mono">
                                    {{ $row['debit'] > 0 ? $fmtMoney($row['debit']) : '' }}
                                </td>
                                <td class="px-3 py-2 text-right font-mono text-success-700 dark:text-success-300">
                                    {{ $row['credit'] > 0 ? $fmtMoney($row['credit']) : '' }}
                                </td>
                                <td class="px-3 py-2 text-right font-mono font-semibold {{ $row['running_balance'] > 0 ? 'text-danger-600' : 'text-gray-600' }}">
                                    {{ $fmtMoney($row['running_balance']) }}
                                </td>
                                <td class="px-3 py-2 text-xs">
                                    @if ($row['mydata_state'])
                                        <span class="text-xs px-2 py-0.5 rounded
                                            {{ $row['mydata_state'] === 'VALID' ? 'bg-success-100 text-success-700' : '' }}
                                            {{ $row['mydata_state'] === 'CANCELLED' ? 'bg-gray-100 text-gray-600' : '' }}
                                            {{ $row['mydata_state'] === 'INVALID' ? 'bg-danger-100 text-danger-700' : '' }}">
                                            {{ $row['mydata_state'] }}
                                        </span>
                                        @if ($row['mydata_mark'])
                                            <div class="text-xs text-gray-500 font-mono mt-0.5">{{ $row['mydata_mark'] }}</div>
                                        @endif
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-2 text-xs text-gray-500">
                Εμφανίζονται {{ count($ledger->ledger) }} κινήσεις.
                Το υπόλοιπο υπολογίζεται από ολόκληρη την ιστορία, ανεξάρτητα από τα φίλτρα.
            </div>
        @endif
    </div>

    @endif {{-- hasAnyActivity --}}

    {{-- ============= Section 6: WHMCS comparison panel (collapsible) ============= --}}
    @if ($hasWhmcs)
        <div class="mt-6 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 p-4">
            <div class="flex items-center justify-between">
                <div class="text-sm font-semibold">
                    Τιμολόγια από WHMCS (πελάτης #{{ $cust->whmcs_client_id }})
                </div>
                <div class="flex gap-2">
                    @if ($showWhmcsPanel && $whmcsLedger !== null)
                        <button type="button" wire:click="refreshWhmcsLedger" class="text-xs text-primary-600 hover:underline">
                            Ανανέωση
                        </button>
                    @endif
                    <button type="button" wire:click="toggleWhmcsPanel" class="text-xs text-primary-600 hover:underline">
                        {{ $showWhmcsPanel ? 'Απόκρυψη' : 'Εμφάνιση' }}
                    </button>
                </div>
            </div>

            @if ($showWhmcsPanel)
                <div class="mt-4">
                    @if ($whmcsLedger === null)
                        <div class="text-center text-gray-500 py-4 text-sm">Φόρτωση…</div>
                    @else
                        @include('filament.customers.whmcs-invoices-panel', [
                            'customer' => $cust,
                            'result' => $whmcsLedger,
                        ])
                    @endif
                </div>
            @endif
        </div>
    @endif
</x-filament-panels::page>
