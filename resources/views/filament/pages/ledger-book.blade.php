<x-filament-panels::page>
    @php
        $money = fn ($v) => '€ ' . number_format((float) $v, 2, ',', '.');
        $result = $this->getResult();
        $categoryOptions = $this->getCategoryOptions();
    @endphp

    {{-- Φίλτρα --}}
    <x-filament::section>
        <x-slot name="heading">Φίλτρα</x-slot>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <label class="flex flex-col gap-1 text-sm">
                <span class="font-medium text-gray-700 dark:text-gray-300">Από</span>
                <input type="date" wire:model.live="from"
                    class="fi-input rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800" />
            </label>
            <label class="flex flex-col gap-1 text-sm">
                <span class="font-medium text-gray-700 dark:text-gray-300">Έως</span>
                <input type="date" wire:model.live="to"
                    class="fi-input rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800" />
            </label>
            <label class="flex flex-col gap-1 text-sm">
                <span class="font-medium text-gray-700 dark:text-gray-300">Βιβλίο</span>
                <select wire:model.live="book"
                    class="fi-select rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800">
                    <option value="all">Όλα</option>
                    <option value="income">Έσοδα</option>
                    <option value="expense">Έξοδα</option>
                </select>
            </label>
            <label class="flex flex-col gap-1 text-sm">
                <span class="font-medium text-gray-700 dark:text-gray-300">Κατηγορία</span>
                <select wire:model.live="category"
                    class="fi-select rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800">
                    <option value="">— όλες —</option>
                    @foreach ($categoryOptions as $code => $label)
                        <option value="{{ $code }}">{{ $label }}</option>
                    @endforeach
                </select>
            </label>
        </div>
        <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
            Περίοδος: {{ $result->periodLabel }} · read-only — η σελίδα δεν τροποποιεί τίποτα.
        </p>
    </x-filament::section>

    {{-- Σύνολα περιόδου --}}
    <x-filament::section>
        <x-slot name="heading">Σύνολα περιόδου</x-slot>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                <div class="text-sm text-gray-500 dark:text-gray-400">Έσοδα ({{ $result->incomeCount() }})</div>
                <div class="text-xl font-bold text-success-600 dark:text-success-400">{{ $money($result->incomeNet()) }}</div>
                <div class="text-xs text-gray-500 dark:text-gray-400">
                    ΦΠΑ εκροών {{ $money($result->incomeVat()) }} · μικτό {{ $money($result->incomeGross()) }}
                </div>
            </div>
            <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                <div class="text-sm text-gray-500 dark:text-gray-400">Έξοδα ({{ $result->expenseCount() }})</div>
                <div class="text-xl font-bold text-danger-600 dark:text-danger-400">{{ $money($result->expenseNet()) }}</div>
                <div class="text-xs text-gray-500 dark:text-gray-400">
                    ΦΠΑ εισροών {{ $money($result->expenseVat()) }} · μικτό {{ $money($result->expenseGross()) }}
                </div>
            </div>
            <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                <div class="text-sm text-gray-500 dark:text-gray-400">ΦΠΑ εκροών − εισροών</div>
                <div @class([
                    'text-xl font-bold',
                    'text-danger-600 dark:text-danger-400' => $result->vatBalance() > 0,
                    'text-success-600 dark:text-success-400' => $result->vatBalance() <= 0,
                ])>{{ $money(abs($result->vatBalance())) }}</div>
                <div class="text-xs text-gray-500 dark:text-gray-400">
                    {{ $result->vatBalance() > 0 ? 'Προς απόδοση' : 'Πιστωτικό υπόλοιπο' }}
                </div>
            </div>
        </div>
    </x-filament::section>

    {{-- Σύνολα ανά κατηγορία --}}
    @foreach (['income' => 'Έσοδα ανά κατηγορία', 'expense' => 'Έξοδα ανά κατηγορία'] as $bk => $heading)
        @php $subtotals = $result->categorySubtotals($bk); @endphp
        @if (! empty($subtotals))
            <x-filament::section collapsible>
                <x-slot name="heading">{{ $heading }}</x-slot>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 text-left text-gray-500 dark:border-gray-700 dark:text-gray-400">
                                <th class="py-2 pr-4">Κατηγορία</th>
                                <th class="py-2 pr-4 text-right">Πλήθος</th>
                                <th class="py-2 pr-4 text-right">Καθαρό</th>
                                <th class="py-2 pr-4 text-right">ΦΠΑ</th>
                                <th class="py-2 text-right">Σύνολο</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($subtotals as $row)
                                <tr class="border-b border-gray-100 dark:border-gray-800">
                                    <td class="py-2 pr-4">
                                        {{ $row['label'] ?? ($row['code'] ?: '— αταξινόμητο —') }}
                                        @if ($row['code'])
                                            <span class="text-xs text-gray-400">({{ $row['code'] }})</span>
                                        @endif
                                    </td>
                                    <td class="py-2 pr-4 text-right">{{ $row['count'] }}</td>
                                    <td class="py-2 pr-4 text-right">{{ $money($row['net']) }}</td>
                                    <td class="py-2 pr-4 text-right">{{ $money($row['vat']) }}</td>
                                    <td class="py-2 text-right font-medium">{{ $money($row['gross']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-filament::section>
        @endif
    @endforeach

    {{-- Ημερολόγιο --}}
    <x-filament::section>
        <x-slot name="heading">Ημερολόγιο</x-slot>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-left text-gray-500 dark:border-gray-700 dark:text-gray-400">
                        <th class="py-2 pr-4">Ημ/νία</th>
                        <th class="py-2 pr-4">Βιβλίο</th>
                        <th class="py-2 pr-4">Παραστατικό</th>
                        <th class="py-2 pr-4">Είδος</th>
                        <th class="py-2 pr-4">Αντισυμβαλλόμενος</th>
                        <th class="py-2 pr-4">ΑΦΜ</th>
                        <th class="py-2 pr-4">Κατηγορία</th>
                        <th class="py-2 pr-4 text-right">Καθαρό</th>
                        <th class="py-2 pr-4 text-right">ΦΠΑ</th>
                        <th class="py-2 text-right">Σύνολο</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($result->rows as $row)
                        <tr class="border-b border-gray-100 dark:border-gray-800">
                            <td class="py-2 pr-4 whitespace-nowrap">{{ $row->date->format('d/m/Y') }}</td>
                            <td class="py-2 pr-4">
                                <x-filament::badge :color="$row->book === 'income' ? 'success' : 'danger'">
                                    {{ $row->book === 'income' ? 'Έσοδο' : 'Έξοδο' }}
                                </x-filament::badge>
                                @if ($row->isCredit)
                                    <span class="text-xs text-warning-600 dark:text-warning-400">πιστωτικό</span>
                                @endif
                            </td>
                            <td class="py-2 pr-4 whitespace-nowrap font-medium">{{ $row->doc }}</td>
                            <td class="py-2 pr-4">{{ $row->docType }}</td>
                            <td class="py-2 pr-4">{{ $row->counterparty ?? '—' }}</td>
                            <td class="py-2 pr-4 whitespace-nowrap">{{ $row->afm ?? '—' }}</td>
                            <td class="py-2 pr-4">
                                {{ $row->categoryLabel ?? '—' }}
                                @if ($row->categoryCode)
                                    <span class="text-xs text-gray-400">({{ $row->categoryCode }})</span>
                                @endif
                            </td>
                            <td class="py-2 pr-4 text-right whitespace-nowrap">{{ $money($row->net) }}</td>
                            <td class="py-2 pr-4 text-right whitespace-nowrap">{{ $money($row->vat) }}</td>
                            <td class="py-2 text-right whitespace-nowrap font-medium">{{ $money($row->gross) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="10" class="py-6 text-center text-gray-500 dark:text-gray-400">
                                Καμία εγγραφή στην περίοδο.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
