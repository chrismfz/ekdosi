<x-filament-panels::page>
    @php($r = $this->getResult())

    <div class="flex gap-3 mb-4">
        <label class="text-sm">
            <span class="block text-xs fi-color-gray mb-1">Έτος</span>
            <select wire:model.live="year"
                class="rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 px-2 py-1 text-sm">
                @foreach ($this->availableYears() as $y)
                    <option value="{{ $y }}">{{ $y }}</option>
                @endforeach
            </select>
        </label>
    </div>

    {{-- Totals --}}
    @php($deltaTotal = $r['total_net'] - $r['prior_total_net'])
    <div class="grid grid-cols-2 gap-3 md:grid-cols-4 mb-4">
        <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
            <div class="text-xs fi-color-gray">Καθαρά έσοδα {{ $r['year'] }}</div>
            <div class="text-lg font-bold">{{ $this->fmt($r['total_net']) }}</div>
        </div>
        <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
            <div class="text-xs fi-color-gray">ΦΠΑ εκροών</div>
            <div class="text-lg font-bold">{{ $this->fmt($r['total_vat']) }}</div>
        </div>
        <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
            <div class="text-xs fi-color-gray">Μεικτό</div>
            <div class="text-lg font-bold">{{ $this->fmt($r['total_gross']) }}</div>
        </div>
        <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
            <div class="text-xs fi-color-gray">Μεταβολή vs {{ $r['year'] - 1 }}</div>
            <div class="text-lg font-bold {{ $deltaTotal > 0.005 ? 'text-success-600 dark:text-success-400' : ($deltaTotal < -0.005 ? 'text-danger-600 dark:text-danger-400' : '') }}">
                {{ $deltaTotal >= 0 ? '+' : '' }}{{ $this->fmt($deltaTotal) }}
            </div>
        </div>
    </div>

    <x-filament::section>
        @if (count($r['rows']) === 0)
            <div class="text-center fi-color-gray py-6 text-sm">Δεν βρέθηκαν έσοδα για το {{ $r['year'] }}.</div>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs uppercase text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-white/10">
                            <th class="py-2 pr-4">Κατηγορία</th>
                            <th class="py-2 px-3 text-right">Καθαρή αξία</th>
                            <th class="py-2 px-3 text-right">ΦΠΑ</th>
                            <th class="py-2 px-3 text-right">Μεικτό</th>
                            <th class="py-2 px-3 text-right">Γραμμές</th>
                            <th class="py-2 px-3 text-right">% τζίρου</th>
                            <th class="py-2 px-3 text-right">Πέρσι</th>
                            <th class="py-2 pl-3 text-right">Μεταβολή</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                        @foreach ($r['rows'] as $row)
                            <tr @class(['bg-warning-50 dark:bg-warning-950/40' => $row['category_id'] === null])>
                                <td class="py-2 pr-4 font-medium">
                                    {{ $row['name'] }}
                                    @if ($row['category_id'] === null)
                                        <span class="text-xs fi-color-gray">(χωρίς κατηγορία)</span>
                                    @endif
                                </td>
                                <td class="py-2 px-3 text-right font-mono whitespace-nowrap">{{ $this->fmt($row['net']) }}</td>
                                <td class="py-2 px-3 text-right font-mono whitespace-nowrap fi-color-gray">{{ $this->fmt($row['vat']) }}</td>
                                <td class="py-2 px-3 text-right font-mono whitespace-nowrap">{{ $this->fmt($row['gross']) }}</td>
                                <td class="py-2 px-3 text-right font-mono">{{ $row['lines'] }}</td>
                                <td class="py-2 px-3 text-right font-mono">{{ number_format($row['pct'], 1) }}%</td>
                                <td class="py-2 px-3 text-right font-mono whitespace-nowrap fi-color-gray">{{ $this->fmt($row['prior_net']) }}</td>
                                <td class="py-2 pl-3 text-right font-mono whitespace-nowrap {{ $row['delta'] > 0.005 ? 'text-success-600 dark:text-success-400' : ($row['delta'] < -0.005 ? 'text-danger-600 dark:text-danger-400' : '') }}">
                                    {{ $row['delta'] >= 0 ? '+' : '' }}{{ $this->fmt($row['delta']) }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="border-t-2 border-gray-300 dark:border-white/20 font-bold">
                            <td class="py-2 pr-4">ΣΥΝΟΛΟ</td>
                            <td class="py-2 px-3 text-right font-mono whitespace-nowrap">{{ $this->fmt($r['total_net']) }}</td>
                            <td class="py-2 px-3 text-right font-mono whitespace-nowrap">{{ $this->fmt($r['total_vat']) }}</td>
                            <td class="py-2 px-3 text-right font-mono whitespace-nowrap">{{ $this->fmt($r['total_gross']) }}</td>
                            <td class="py-2 px-3"></td>
                            <td class="py-2 px-3 text-right font-mono">{{ $r['total_net'] != 0.0 ? '100%' : '—' }}</td>
                            <td class="py-2 px-3 text-right font-mono whitespace-nowrap">{{ $this->fmt($r['prior_total_net']) }}</td>
                            <td class="py-2 pl-3 text-right font-mono whitespace-nowrap">{{ $deltaTotal >= 0 ? '+' : '' }}{{ $this->fmt($deltaTotal) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <div class="mt-3 text-xs fi-color-gray leading-relaxed">
                Έσοδα ζωντανών παραστατικών· τα πιστωτικά αφαιρούνται. Οι WHMCS γραμμές μπαίνουν σε κατηγορία
                μέσω «Ρυθμίσεις → Αντιστοίχιση WHMCS» (νέα παραστατικά)· οι χειροκίνητες γραμμές μέσω της
                κατηγορίας του προϊόντος. Ό,τι δεν έχει κατηγορία εμφανίζεται ως «Αταξινόμητα».
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
