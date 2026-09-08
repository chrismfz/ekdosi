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

    {{-- ============= Σημειώσεις (εσωτερικές) ============= --}}
    @php($internalNotes = $cust->internalNotes)
    @if ($internalNotes->isNotEmpty())
        @php($shownNotes = $internalNotes->take(8))
        <x-filament::section>
            <x-slot name="heading">Σημειώσεις (εσωτερικές)</x-slot>
            <x-slot name="description">Δεν εκτυπώνονται και δεν αποστέλλονται στην ΑΑΔΕ.</x-slot>
            <div class="space-y-2">
                @foreach ($shownNotes as $note)
                    <div class="flex items-start gap-2 text-sm">
                        @if ($note->is_pinned)
                            <x-filament::icon icon="heroicon-s-bookmark" class="h-4 w-4 mt-0.5 text-amber-500" />
                        @endif
                        <div class="space-y-0.5">
                            <div class="whitespace-pre-line">{{ $note->body }}</div>
                            <div class="text-xs fi-color-gray flex items-center gap-1">
                                @if ($note->sourceLabel())
                                    <x-filament::badge color="gray" size="sm">{{ $note->sourceLabel() }}</x-filament::badge>
                                @endif
                                <span>{{ $note->author?->name ?? 'Σύστημα' }} · {{ $note->created_at?->format('d/m/Y H:i') }}</span>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
            <div class="mt-3 text-xs fi-color-gray">
                @if ($internalNotes->count() > $shownNotes->count())
                    +{{ $internalNotes->count() - $shownNotes->count() }} ακόμη ·
                @endif
                Διαχείριση: από την «Επεξεργασία» του πελάτη → καρτέλα «Σημειώσεις (εσωτερικές)».
            </div>
        </x-filament::section>
    @endif

    {{-- ============= Συνημμένα ============= --}}
    @php($attachments = $cust->attachments)
    @if ($attachments->isNotEmpty())
        <x-filament::section>
            <x-slot name="heading">Συνημμένα ({{ $attachments->count() }})</x-slot>
            <div class="space-y-1">
                @foreach ($attachments->take(8) as $att)
                    <div class="flex items-center gap-2 text-sm">
                        <x-filament::icon icon="heroicon-o-paper-clip" class="h-4 w-4 fi-color-gray" />
                        <span>{{ $att->title ?: $att->original_name }}</span>
                        <span class="text-xs fi-color-gray">{{ $att->humanSize() }}</span>
                    </div>
                @endforeach
            </div>
            <div class="mt-3 text-xs fi-color-gray">
                Λήψη/διαχείριση: από την «Επεξεργασία» του πελάτη → καρτέλα «Συνημμένα».
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

        {{-- ============= Πρόχειρα (unissued drafts) =============
             Deliberately its OWN section, above the ledger and outside every money
             figure: a draft is not a movement, so it must not touch the balance —
             but the operator still needs to find it. Warning-toned so it never
             reads as an issued document. --}}
        @if (count($this->draftInvoices) > 0)
            <x-filament::section>
                <x-slot name="heading">
                    Πρόχειρα ({{ count($this->draftInvoices) }})
                </x-slot>
                <x-slot name="description">
                    Μη οριστικοποιημένα/μη υποβληθέντα — δεν μετρούν στο υπόλοιπο. Ανοίξτε για επεξεργασία και έκδοση.
                </x-slot>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left fi-color-gray border-b border-gray-200 dark:border-white/10">
                                <th class="py-2 pr-3 font-medium">Κωδικός</th>
                                <th class="py-2 px-3 font-medium">Τύπος</th>
                                <th class="py-2 px-3 font-medium whitespace-nowrap">Ημ/νία</th>
                                <th class="py-2 px-3 font-medium text-right">Αξία (με ΦΠΑ)</th>
                                <th class="py-2 pl-3 font-medium text-right">Ενέργειες</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($this->draftInvoices as $draft)
                                <tr class="border-b border-gray-100 dark:border-white/5">
                                    <td class="py-2 pr-3">
                                        <x-filament::badge color="warning" size="sm">Πρόχειρο</x-filament::badge>
                                        <span class="font-mono">{{ $draft['invcode'] ?: '—' }}</span>
                                    </td>
                                    <td class="py-2 px-3">{{ $draft['type'] ?: '—' }}</td>
                                    <td class="py-2 px-3 font-mono whitespace-nowrap fi-color-gray">
                                        {{ $draft['issued_at'] ? \Illuminate\Support\Carbon::parse($draft['issued_at'])->format('d/m/Y') : '—' }}
                                    </td>
                                    <td class="py-2 px-3 text-right font-mono whitespace-nowrap">
                                        {{ number_format($draft['gross'], 2) }} €
                                    </td>
                                    <td class="py-2 pl-3 text-right whitespace-nowrap">
                                        <x-filament::link :href="$draft['view_url']" size="sm">Άνοιγμα</x-filament::link>
                                        @if ($draft['edit_url'])
                                            <span class="fi-color-gray">·</span>
                                            <x-filament::link :href="$draft['edit_url']" size="sm">Επεξεργασία</x-filament::link>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-filament::section>
        @endif

        {{-- ============= Καρτέλα κινήσεων (the main table) ============= --}}
        <x-filament::section>
            <x-slot name="heading">Καρτέλα κινήσεων</x-slot>
            <x-slot name="description">
                Το υπόλοιπο υπολογίζεται από ολόκληρη την ιστορία, ανεξάρτητα από τα φίλτρα.
            </x-slot>

            {{-- Period totals — shown when a year is picked in the table filter
                 above; reuses the cached per-year breakdown (no extra query). --}}
            @php($period = $this->getPeriodSummary())
            @if ($period)
                <div class="grid grid-cols-2 gap-3 md:grid-cols-4 mb-4">
                    <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
                        <div class="text-xs fi-color-gray">Τζίρος {{ $period['year'] }} (καθαρό)</div>
                        <div class="text-lg font-bold">{{ \App\Support\Money::eur($period['net']) }}</div>
                        <div class="text-xs fi-color-gray">{{ $period['invoice_count'] }} παραστατικά</div>
                    </div>
                    <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
                        <div class="text-xs fi-color-gray">Αξία με ΦΠΑ {{ $period['year'] }}</div>
                        <div class="text-lg font-bold">{{ \App\Support\Money::eur($period['gross']) }}</div>
                    </div>
                    <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
                        <div class="text-xs fi-color-gray">Εισπράξεις {{ $period['year'] }}</div>
                        <div class="text-lg font-bold text-success-600 dark:text-success-400">{{ \App\Support\Money::eur($period['paid']) }}</div>
                    </div>
                    <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
                        <div class="text-xs fi-color-gray">Υπόλοιπο τέλους {{ $period['year'] }}</div>
                        <div class="text-lg font-bold {{ ($period['year_end_balance'] ?? 0) > 0 ? 'text-danger-600 dark:text-danger-400' : '' }}">
                            {{ $period['year_end_balance'] !== null ? \App\Support\Money::eur($period['year_end_balance']) : '—' }}
                        </div>
                    </div>
                </div>
            @endif

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
