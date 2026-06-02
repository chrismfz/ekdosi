<x-filament-panels::page>
    @php
        $cust = $this->record;
        $stats = $this->cachedStatsBlock['stats'] ?? [];
        $aging = $this->cachedStatsBlock['aging'] ?? [];
        $yearly = $this->cachedStatsBlock['yearly'] ?? [];
    @endphp

    {{-- ============= Identity ============= --}}
    <x-filament::section>
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="space-y-1">
                <div class="text-base font-bold">{{ $cust->name }}</div>
                <div class="text-sm fi-color-gray">
                    @if ($cust->afm)<span class="font-medium">ΑΦΜ:</span> {{ $cust->afm }}@endif
                    @if ($cust->tax_office) &middot; <span class="font-medium">ΔΟΥ:</span> {{ $cust->tax_office }}@endif
                    @if ($cust->occupation) &middot; <span class="font-medium">Δραστηριότητα:</span> {{ $cust->occupation }}@endif
                </div>
                @if ($cust->address1)
                    <div class="text-sm fi-color-gray">
                        {{ $cust->address1 }}{{ $cust->city ? ', ' . $cust->city : '' }}{{ $cust->postcode ? ' ' . $cust->postcode : '' }}
                    </div>
                @endif
                {{-- Contact + commercial terms we already store but never surfaced --}}
                @if ($cust->phone1 || $cust->phone2 || $cust->email)
                    <div class="text-sm fi-color-gray">
                        @if ($cust->phone1)<span class="font-medium">Τηλ:</span> {{ $cust->phone1 }}@endif
                        @if ($cust->phone2) &middot; {{ $cust->phone2 }}@endif
                        @if ($cust->email) &middot; <span class="font-medium">Email:</span> {{ $cust->email }}@endif
                    </div>
                @endif
                @if ($cust->paymentMethod || (float) $cust->discount > 0)
                    <div class="text-sm fi-color-gray">
                        @if ($cust->paymentMethod)<span class="font-medium">Τρόπος πληρωμής:</span> {{ $cust->paymentMethod->description }}@endif
                        @if ((float) $cust->discount > 0) &middot; <span class="font-medium">Έκπτωση:</span> {{ rtrim(rtrim(number_format((float) $cust->discount, 2), '0'), '.') }}%@endif
                    </div>
                @endif
                @if (filled($cust->details))
                    <div class="text-sm fi-color-gray italic">{{ $cust->details }}</div>
                @endif
            </div>

            <div class="flex flex-wrap items-start gap-2">
                @if ($cust->needs_immediate_invoice)
                    <x-filament::badge color="warning" icon="heroicon-o-bolt">Άμεση τιμολόγηση</x-filament::badge>
                @endif
                @if ((int) $cust->whmcs_reseller_routes > 0)
                    <x-filament::badge color="success">Μεταπωλητής</x-filament::badge>
                @endif
                @if ($cust->whmcs_client_id)
                    <x-filament::badge color="info">WHMCS #{{ $cust->whmcs_client_id }}</x-filament::badge>
                @endif
            </div>
        </div>
    </x-filament::section>

    {{-- ============= Επαφές ============= --}}
    @php($contacts = $cust->contacts)
    @if ($contacts->isNotEmpty())
        <x-filament::section>
            <x-slot name="heading">Επαφές</x-slot>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($contacts as $contact)
                    <div class="rounded-lg border border-gray-200 dark:border-white/10 p-3 space-y-1">
                        <div class="flex items-center gap-2">
                            <span class="text-sm font-medium">{{ $contact->name }}</span>
                            @if ($contact->is_primary)
                                <x-filament::badge color="success" size="sm">Κύρια</x-filament::badge>
                            @endif
                        </div>
                        @if ($contact->role)
                            <div class="text-xs fi-color-gray">{{ $contact->role }}</div>
                        @endif
                        @if ($contact->phone)
                            <div class="text-sm">{{ $contact->phone }}</div>
                        @endif
                        @if ($contact->email)
                            <div class="text-sm">
                                <a href="mailto:{{ $contact->email }}" class="fi-link">{{ $contact->email }}</a>
                            </div>
                        @endif
                        @if (filled($contact->notes))
                            <div class="text-xs fi-color-gray italic">{{ $contact->notes }}</div>
                        @endif
                    </div>
                @endforeach
            </div>
            <div class="mt-3 text-xs fi-color-gray">
                Διαχείριση επαφών: από την «Επεξεργασία» του πελάτη → καρτέλα «Επαφές».
            </div>
        </x-filament::section>
    @endif

    @if (! $this->hasActivity())
        <x-filament::section>
            <div class="text-center fi-color-gray py-6">
                Αυτός ο πελάτης δεν έχει κινήσεις ακόμη.
            </div>
        </x-filament::section>
    @else
        {{-- ============= KPI stats ============= --}}
        @livewire(
            \App\Filament\Resources\Customers\Widgets\CustomerLedgerStats::class,
            ['ledgerStats' => $stats, 'ledgerYearly' => $yearly],
            key('ledger-stats-' . $cust->id)
        )

        {{-- ============= Aging buckets ============= --}}
        @livewire(
            \App\Filament\Resources\Customers\Widgets\CustomerLedgerAging::class,
            ['ledgerAging' => $aging, 'ledgerStats' => $stats],
            key('ledger-aging-' . $cust->id)
        )

        {{-- ============= Year comparison + balance trend (≥2 years) ============= --}}
        @if ($this->hasBalanceTrend())
            @livewire(
                \App\Filament\Resources\Customers\Widgets\CustomerLedgerRevenueChart::class,
                ['ledgerYearly' => $yearly],
                key('ledger-revenue-' . $cust->id)
            )

            @livewire(
                \App\Filament\Resources\Customers\Widgets\CustomerLedgerBalanceChart::class,
                ['ledgerYearly' => $yearly],
                key('ledger-chart-' . $cust->id)
            )
        @endif

        {{-- ============= Καρτέλα κινήσεων (the main table) ============= --}}
        <x-filament::section>
            <x-slot name="heading">Καρτέλα κινήσεων</x-slot>
            <x-slot name="description">
                Το υπόλοιπο υπολογίζεται από ολόκληρη την ιστορία, ανεξάρτητα από τα φίλτρα.
            </x-slot>

            {{ $this->table }}
        </x-filament::section>

        {{-- ============= Συχνά προϊόντα/υπηρεσίες ============= --}}
        @if (count($this->topProducts) > 0)
            <x-filament::section>
                <x-slot name="heading">Συχνά προϊόντα/υπηρεσίες</x-slot>
                <x-slot name="description">
                    Τι αγοράζει συχνότερα ο πελάτης (από ζωντανά παραστατικά, χωρίς πιστωτικά).
                </x-slot>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left fi-color-gray border-b border-gray-200 dark:border-white/10">
                                <th class="py-2 pr-3 font-medium">Είδος</th>
                                <th class="py-2 px-3 font-medium text-right">Φορές</th>
                                <th class="py-2 px-3 font-medium text-right">Ποσότητα</th>
                                <th class="py-2 px-3 font-medium text-right">Καθαρή αξία</th>
                                <th class="py-2 pl-3 font-medium whitespace-nowrap">Τελευταία</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($this->topProducts as $row)
                                <tr class="border-b border-gray-100 dark:border-white/5">
                                    <td class="py-2 pr-3">
                                        {{ $row['label'] }}
                                        @if (! empty($row['sku']))
                                            <span class="fi-color-gray">· {{ $row['sku'] }}</span>
                                        @endif
                                    </td>
                                    <td class="py-2 px-3 text-right font-mono">{{ $row['times'] }}</td>
                                    <td class="py-2 px-3 text-right font-mono whitespace-nowrap">
                                        {{ rtrim(rtrim(number_format($row['qty'], 3), '0'), '.') }}{{ $row['unit'] ? ' ' . $row['unit'] : '' }}
                                    </td>
                                    <td class="py-2 px-3 text-right font-mono whitespace-nowrap">
                                        {{ number_format($row['net'], 2) }} €
                                    </td>
                                    <td class="py-2 pl-3 font-mono whitespace-nowrap fi-color-gray">
                                        {{ $row['last_at'] ? \Illuminate\Support\Carbon::parse($row['last_at'])->format('d/m/Y') : '—' }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-filament::section>
        @endif
    @endif

    {{-- ============= WHMCS comparison (collapsible) ============= --}}
    @if ($this->hasWhmcsLink())
        <x-filament::section collapsible :collapsed="! $showWhmcsPanel">
            <x-slot name="heading">
                Τιμολόγια από WHMCS (πελάτης #{{ $cust->whmcs_client_id }})
            </x-slot>

            <div class="flex justify-end gap-3 mb-3">
                @if ($showWhmcsPanel && $whmcsLedger !== null)
                    <x-filament::link tag="button" wire:click="refreshWhmcsLedger" size="sm">
                        Ανανέωση
                    </x-filament::link>
                @endif
                <x-filament::link tag="button" wire:click="toggleWhmcsPanel" size="sm">
                    {{ $showWhmcsPanel ? 'Απόκρυψη' : 'Φόρτωση δεδομένων WHMCS' }}
                </x-filament::link>
            </div>

            @if ($showWhmcsPanel)
                @if ($whmcsLedger === null)
                    <div class="text-center fi-color-gray py-4 text-sm">Φόρτωση…</div>
                @else
                    @include('filament.customers.whmcs-invoices-panel', [
                        'customer' => $cust,
                        'result' => $whmcsLedger,
                    ])
                @endif
            @endif
        </x-filament::section>
    @endif
</x-filament-panels::page>
