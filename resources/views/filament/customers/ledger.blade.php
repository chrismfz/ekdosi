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
            </div>

            @if ($cust->whmcs_client_id)
                <x-filament::badge color="info">
                    WHMCS #{{ $cust->whmcs_client_id }}
                </x-filament::badge>
            @endif
        </div>
    </x-filament::section>

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
