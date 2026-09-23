<x-filament-panels::page>
    @php
        $money = fn ($v) => \App\Support\Money::eur($v);
        $result = $this->getResult();
        $tenant = \Filament\Facades\Filament::getTenant();
        $ledgerUrl = fn ($id) => \App\Filament\Resources\Customers\CustomerResource::getUrl('ledger', ['record' => $id, 'tenant' => $tenant]);
        $insights = $this->getInsights();
        $summary = $insights['summary'];
        $canRemind = $this->canRemind();
        $remindersUrl = auth()->user()?->can('viewAny', \App\Models\InvoiceReminder::class)
            ? \App\Filament\Resources\InvoiceReminders\InvoiceReminderResource::getUrl('index')
            : null;
    @endphp

    <x-filament::section>
        <x-slot name="heading">Υπενθυμίσεις πληρωμής</x-slot>
        <x-slot name="description">Πώς πάνε οι υπενθυμίσεις — και τι ανοιχτό ΔΕΝ καλύπτουν.</x-slot>

        <div class="grid grid-cols-1 gap-4 md:grid-cols-4">
            <div class="rounded-lg border border-gray-200 p-3">
                <div class="text-xs text-gray-500">Προς έγκριση</div>
                <div class="text-2xl font-bold {{ $summary['awaiting'] > 0 ? 'text-warning-600' : '' }}">
                    @if ($remindersUrl && $summary['awaiting'] > 0)
                        <a href="{{ $remindersUrl }}" class="hover:underline">{{ $summary['awaiting'] }}</a>
                    @else
                        {{ $summary['awaiting'] }}
                    @endif
                </div>
            </div>
            <div class="rounded-lg border border-gray-200 p-3">
                <div class="text-xs text-gray-500">Αποτυχίες αποστολής</div>
                <div class="text-2xl font-bold {{ $summary['failed'] > 0 ? 'text-danger-600' : '' }}">{{ $summary['failed'] }}</div>
            </div>
            <div class="rounded-lg border border-gray-200 p-3">
                <div class="text-xs text-gray-500">Εστάλησαν (30 ημ.)</div>
                <div class="text-2xl font-bold">{{ $summary['sent30'] }}</div>
            </div>
            <div class="rounded-lg border border-gray-200 p-3">
                <div class="text-xs text-gray-500">Εξοφλήθηκαν μετά από υπενθύμιση ({{ \App\Services\Reminders\ReminderInsights::PAID_AFTER_DAYS }} ημ.)</div>
                <div class="text-2xl font-bold text-success-600">{{ $summary['paid_after']['count'] }}</div>
                @if ($summary['paid_after']['count'] > 0)
                    <div class="text-xs text-gray-500">{{ $money($summary['paid_after']['amount']) }}</div>
                @endif
            </div>
        </div>

        <div class="mt-4 text-sm font-medium">Δεν υπενθυμίζονται</div>
        <div class="grid grid-cols-1 gap-4 md:grid-cols-4 mt-2">
            @foreach (\App\Services\Reminders\ReminderInsights::GAP_LABELS as $gap => $label)
                @php $g = $insights['gaps'][$gap] ?? ['count' => 0, 'amount' => 0]; @endphp
                @if ($g['count'] > 0)
                    <button type="button"
                            class="rounded-lg border border-gray-200 p-3 text-left w-full cursor-pointer hover:bg-gray-50"
                            wire:click="mountAction('gap', { kind: '{{ $gap }}' })">
                        <div class="text-xs text-gray-500">{{ $label }}</div>
                        <div class="text-xl font-bold">{{ $g['count'] }}</div>
                        <div class="text-xs text-gray-500">{{ $money($g['amount']) }} · προβολή</div>
                    </button>
                @else
                    <div class="rounded-lg border border-gray-200 p-3">
                        <div class="text-xs text-gray-500">{{ $label }}</div>
                        <div class="text-xl font-bold text-gray-400">0</div>
                    </div>
                @endif
            @endforeach
        </div>
    </x-filament::section>

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
                            <th class="py-2 pr-4 text-right">Παλαιότερο</th>
                            <th class="py-2 pr-4">Επόμενο βήμα</th>
                            <th class="py-2 pr-4">Τελ. επαφή</th>
                            <th class="py-2 pr-4">Τελ. υπενθύμιση</th>
                            <th class="py-2"></th>
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
                                <td class="py-2 pr-4 text-right whitespace-nowrap">{{ $row->oldestDays !== null ? $row->oldestDays.' ημ.' : '—' }}</td>
                                <td class="py-2 pr-4 whitespace-nowrap">
                                    @if ($row->collectionNextStepAt || $row->collectionNextStepNote || $row->collectionAssignee)
                                        <div class="font-medium">{{ $row->collectionNextStepAt ?? '—' }}</div>
                                        @if ($row->collectionNextStepNote)
                                            <div class="text-xs text-gray-500 dark:text-gray-400">{{ $row->collectionNextStepNote }}</div>
                                        @endif
                                        @if ($row->collectionAssignee)
                                            <div class="text-xs text-gray-400 dark:text-gray-500">👤 {{ $row->collectionAssignee }}</div>
                                        @endif
                                    @else
                                        <span class="text-gray-400 dark:text-gray-500">—</span>
                                    @endif
                                </td>
                                <td class="py-2 pr-4 whitespace-nowrap">{{ $row->collectionLastContactAt ?? '—' }}</td>
                                @php $last = $this->lastReminder($row->customerId); @endphp
                                <td class="py-2 pr-4 whitespace-nowrap">
                                    @if ($last)
                                        <div>{{ $last['sent_at']->format('d/m/Y') }}</div>
                                        <div class="text-xs text-gray-500">{{ \App\Models\InvoiceReminder::STAGE_LABELS[$last['stage']] ?? $last['stage'] }}</div>
                                    @else
                                        <span class="text-gray-400">—</span>
                                    @endif
                                </td>
                                <td class="py-2 text-right whitespace-nowrap">
                                    @if ($canRemind)
                                        <x-filament::icon-button
                                            icon="heroicon-m-bell-alert"
                                            color="gray"
                                            size="sm"
                                            label="Υπενθύμιση τώρα"
                                            wire:click="mountAction('remind', { customer: {{ $row->customerId }} })"
                                        />
                                    @endif
                                    <x-filament::icon-button
                                        icon="heroicon-m-phone-arrow-up-right"
                                        color="gray"
                                        size="sm"
                                        label="Εργασία είσπραξης"
                                        wire:click="mountAction('collection', { customer: {{ $row->customerId }} })"
                                    />
                                </td>
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
                            <td></td>
                            <td></td>
                            <td></td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
