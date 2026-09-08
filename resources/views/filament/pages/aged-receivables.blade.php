<x-filament-panels::page>
    @php
        $money = fn ($v) => \App\Support\Money::eur($v);
        $result = $this->getResult();
        $tenant = \Filament\Facades\Filament::getTenant();
        $ledgerUrl = fn ($id) => \App\Filament\Resources\Customers\CustomerResource::getUrl('ledger', ['record' => $id, 'tenant' => $tenant]);
    @endphp

    <x-filament::section>
        <x-slot name="heading">Ηλικίωση οφειλών</x-slot>
        <x-slot name="description">
            Ανοιχτό υπόλοιπο ανά πελάτη, κατανεμημένο σε ηλικίες (ημέρες από την έκδοση), όπως στην Καρτέλα.
            Read-only · ως {{ now()->format('d/m/Y') }}.
        </x-slot>

        @if ($result->isEmpty())
            <div class="flex items-center gap-2 text-success-600 dark:text-success-400">
                <x-filament::icon icon="heroicon-o-check-circle" class="h-6 w-6" />
                <span class="font-medium">Καμία ανοιχτή οφειλή.</span>
            </div>
        @else
            {{-- Summary cards --}}
            <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
                    <div class="text-xs text-gray-500 dark:text-gray-400">Σύνολο οφειλών ({{ $result->customerCount() }} πελάτες)</div>
                    <div class="text-2xl font-bold">{{ $money($result->grandTotal()) }}</div>
                </div>
                <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
                    <div class="text-xs text-gray-500 dark:text-gray-400">Ληξιπρόθεσμα 61-90</div>
                    <div class="text-2xl font-bold text-warning-600 dark:text-warning-400">{{ $money($result->total61_90()) }}</div>
                </div>
                <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
                    <div class="text-xs text-gray-500 dark:text-gray-400">Ληξιπρόθεσμα 90+</div>
                    <div class="text-2xl font-bold text-danger-600 dark:text-danger-400">{{ $money($result->total90plus()) }}</div>
                </div>
            </div>

            <div class="overflow-x-auto mt-4">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-left text-gray-500 dark:border-white/10 dark:text-gray-400">
                            <th class="py-2 pr-4">Πελάτης</th>
                            <th class="py-2 pr-4">ΑΦΜ</th>
                            <th class="py-2 pr-4 text-right">0-30</th>
                            <th class="py-2 pr-4 text-right">31-60</th>
                            <th class="py-2 pr-4 text-right">61-90</th>
                            <th class="py-2 pr-4 text-right">90+</th>
                            <th class="py-2 pr-4 text-right">Σύνολο</th>
                            <th class="py-2 text-right">Παλαιότερο</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($result->rows as $row)
                            <tr class="border-b border-gray-100 dark:border-white/5">
                                <td class="py-2 pr-4">
                                    <a href="{{ $ledgerUrl($row->customerId) }}"
                                       class="text-primary-600 hover:underline dark:text-primary-400 font-medium">
                                        {{ $row->customerName }}
                                    </a>
                                </td>
                                <td class="py-2 pr-4 whitespace-nowrap">{{ $row->afm ?? '—' }}</td>
                                <td class="py-2 pr-4 text-right whitespace-nowrap">{{ $row->b0_30 ? $money($row->b0_30) : '' }}</td>
                                <td class="py-2 pr-4 text-right whitespace-nowrap">{{ $row->b31_60 ? $money($row->b31_60) : '' }}</td>
                                <td class="py-2 pr-4 text-right whitespace-nowrap text-warning-700 dark:text-warning-400">{{ $row->b61_90 ? $money($row->b61_90) : '' }}</td>
                                <td class="py-2 pr-4 text-right whitespace-nowrap text-danger-700 dark:text-danger-400">{{ $row->b90plus ? $money($row->b90plus) : '' }}</td>
                                <td class="py-2 pr-4 text-right whitespace-nowrap font-semibold">{{ $money($row->total) }}</td>
                                <td class="py-2 text-right whitespace-nowrap">{{ $row->oldestDays !== null ? $row->oldestDays.' ημ.' : '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="border-t-2 border-gray-200 font-semibold dark:border-white/20">
                            <td class="py-2 pr-4" colspan="2">Σύνολα</td>
                            <td class="py-2 pr-4 text-right whitespace-nowrap">{{ $money($result->total0_30()) }}</td>
                            <td class="py-2 pr-4 text-right whitespace-nowrap">{{ $money($result->total31_60()) }}</td>
                            <td class="py-2 pr-4 text-right whitespace-nowrap">{{ $money($result->total61_90()) }}</td>
                            <td class="py-2 pr-4 text-right whitespace-nowrap">{{ $money($result->total90plus()) }}</td>
                            <td class="py-2 pr-4 text-right whitespace-nowrap">{{ $money($result->grandTotal()) }}</td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
