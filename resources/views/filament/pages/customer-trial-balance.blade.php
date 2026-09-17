<x-filament-panels::page>
    @php
        $result = $this->getResult();
        $tenant = \Filament\Facades\Filament::getTenant();
        $ledgerUrl = fn ($id) => \App\Filament\Resources\Customers\CustomerResource::getUrl('ledger', ['record' => $id, 'tenant' => $tenant]);
    @endphp

    {{-- Period --}}
    <div class="flex flex-wrap gap-3">
        <label class="text-sm">
            <span class="block text-xs fi-color-gray mb-1">Από</span>
            <input type="date" wire:model.live="from"
                class="rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 px-2 py-1 text-sm">
        </label>
        <label class="text-sm">
            <span class="block text-xs fi-color-gray mb-1">Έως</span>
            <input type="date" wire:model.live="to"
                class="rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 px-2 py-1 text-sm">
        </label>
    </div>
    <div class="text-xs fi-color-gray mb-4 mt-1">Περίοδος: {{ $result->periodLabel }}</div>

    {{-- Totals --}}
    <div class="grid grid-cols-2 gap-3 md:grid-cols-4 mb-4">
        <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
            <div class="text-xs fi-color-gray">Υπόλοιπο μεταφοράς</div>
            <div class="text-lg font-bold">{{ $this->fmt($result->totalOpening()) }}</div>
        </div>
        <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
            <div class="text-xs fi-color-gray">Χρέωση περιόδου</div>
            <div class="text-lg font-bold">{{ $this->fmt($result->totalDebit()) }}</div>
        </div>
        <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
            <div class="text-xs fi-color-gray">Πίστωση περιόδου</div>
            <div class="text-lg font-bold">{{ $this->fmt($result->totalCredit()) }}</div>
        </div>
        <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
            <div class="text-xs fi-color-gray">Τελικό υπόλοιπο</div>
            <div class="text-lg font-bold">{{ $this->fmt($result->totalClosing()) }}</div>
        </div>
    </div>

    <x-filament::section>
        @if (count($result->rows) === 0)
            <div class="text-center fi-color-gray py-6 text-sm">Καμία κίνηση ή υπόλοιπο πελάτη στην περίοδο.</div>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs uppercase text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-white/10">
                            <th class="py-2 pr-4">Πελάτης</th>
                            <th class="py-2 px-3">ΑΦΜ</th>
                            <th class="py-2 px-3 text-right">Υπόλοιπο μεταφοράς</th>
                            <th class="py-2 px-3 text-right">Χρέωση</th>
                            <th class="py-2 px-3 text-right">Πίστωση</th>
                            <th class="py-2 pl-3 text-right">Τελικό</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                        @foreach ($result->rows as $row)
                            <tr>
                                <td class="py-2 pr-4 font-medium">
                                    <a href="{{ $ledgerUrl($row->customerId) }}" class="text-primary-600 hover:underline dark:text-primary-400">
                                        {{ $row->customerName }}
                                    </a>
                                </td>
                                <td class="py-2 px-3 whitespace-nowrap fi-color-gray">{{ $row->afm ?? '—' }}</td>
                                <td class="py-2 px-3 text-right font-mono whitespace-nowrap fi-color-gray">{{ $this->fmt($row->opening) }}</td>
                                <td class="py-2 px-3 text-right font-mono whitespace-nowrap">{{ $this->fmt($row->debit) }}</td>
                                <td class="py-2 px-3 text-right font-mono whitespace-nowrap">{{ $this->fmt($row->credit) }}</td>
                                <td class="py-2 pl-3 text-right font-mono whitespace-nowrap font-semibold {{ $row->closing > 0.005 ? 'text-danger-600 dark:text-danger-400' : ($row->closing < -0.005 ? 'text-success-600 dark:text-success-400' : '') }}">
                                    {{ $this->fmt($row->closing) }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="border-t-2 border-gray-300 dark:border-white/20 font-bold">
                            <td class="py-2 pr-4" colspan="2">ΣΥΝΟΛΑ</td>
                            <td class="py-2 px-3 text-right font-mono whitespace-nowrap">{{ $this->fmt($result->totalOpening()) }}</td>
                            <td class="py-2 px-3 text-right font-mono whitespace-nowrap">{{ $this->fmt($result->totalDebit()) }}</td>
                            <td class="py-2 px-3 text-right font-mono whitespace-nowrap">{{ $this->fmt($result->totalCredit()) }}</td>
                            <td class="py-2 pl-3 text-right font-mono whitespace-nowrap">{{ $this->fmt($result->totalClosing()) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <div class="mt-3 text-xs fi-color-gray leading-relaxed">
                Ίδια βάση με την Καρτέλα: χρέωση = επί πιστώσει παραστατικά (εισπρακτέο)· πίστωση = εισπράξεις + πιστωτικά.
                Τα τοις-μετρητοίς παραστατικά (εξόφληση στην έκδοση) δεν μετρούν στο υπόλοιπο. Το «Τελικό» ισοσκελίζει με το
                υπόλοιπο της Καρτέλας και τα ανεξόφλητα του dashboard.
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
