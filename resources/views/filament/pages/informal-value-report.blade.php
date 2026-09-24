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
        <label class="text-sm">
            <span class="block text-xs fi-color-gray mb-1">Σειρά</span>
            <select wire:model.live="series"
                class="rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 px-2 py-1 text-sm">
                <option value="">Όλες οι άτυπες</option>
                @foreach ($this->seriesOptions() as $id => $label)
                    <option value="{{ $id }}">{{ $label }}</option>
                @endforeach
            </select>
        </label>
    </div>

    <div class="grid grid-cols-2 gap-3 md:grid-cols-4 mb-4">
        <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
            <div class="text-xs fi-color-gray">Αξία χωρίς χρέωση {{ $r['year'] }} (μεικτό)</div>
            <div class="text-lg font-bold">{{ $this->fmt($r['total_gross']) }}</div>
        </div>
        <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
            <div class="text-xs fi-color-gray">Καθαρή αξία</div>
            <div class="text-lg font-bold">{{ $this->fmt($r['total_net']) }}</div>
        </div>
        <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
            <div class="text-xs fi-color-gray">Άτυπα παραστατικά</div>
            <div class="text-lg font-bold">{{ $r['total_docs'] }}</div>
        </div>
        <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
            <div class="text-xs fi-color-gray">Μετατράπηκαν σε φορολογικό</div>
            <div class="text-lg font-bold">{{ $r['converted_docs'] }} · {{ $this->fmt($r['converted_gross']) }}</div>
        </div>
    </div>

    @if ($r['total_docs'] === 0 && $r['converted_docs'] === 0)
        <x-filament::section>
            <div class="text-center fi-color-gray py-6 text-sm">Δεν βρέθηκαν εκδομένα άτυπα παραστατικά για το {{ $r['year'] }}.</div>
        </x-filament::section>
    @else
        @foreach ([
            ['Ανά πελάτη', $r['by_customer'], true],
            ['Ανά είδος / υπηρεσία', $r['by_item'], false],
            ['Ανά σειρά', $r['by_series'], true],
        ] as [$heading, $rows, $hasDocs])
            <x-filament::section :heading="$heading" class="mb-4">
                @if (count($rows) === 0)
                    <div class="fi-color-gray text-sm">—</div>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead>
                                <tr class="text-left text-xs uppercase text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-white/10">
                                    <th class="py-2 pr-4">{{ $hasDocs ? '' : 'Είδος' }}</th>
                                    <th class="py-2 px-3 text-right">{{ $hasDocs ? 'Παραστατικά' : 'Ποσότητα' }}</th>
                                    <th class="py-2 px-3 text-right">Καθαρή αξία</th>
                                    <th class="py-2 pl-3 text-right">Μεικτό</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                                @foreach ($rows as $row)
                                    <tr>
                                        <td class="py-2 pr-4 font-medium">{{ $row['label'] }}</td>
                                        <td class="py-2 px-3 text-right font-mono">{{ $hasDocs ? $row['docs'] : $this->fmtQty($row['qty']) }}</td>
                                        <td class="py-2 px-3 text-right font-mono whitespace-nowrap">{{ $this->fmt($row['net']) }}</td>
                                        <td class="py-2 pl-3 text-right font-mono whitespace-nowrap">{{ $this->fmt($row['gross']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-filament::section>
        @endforeach

        <div class="mt-3 text-xs fi-color-gray leading-relaxed">
            Εκδομένα (όχι πρόχειρα, όχι ακυρωμένα) παραστατικά άτυπων σειρών — δικά μας, φίλοι, δοκιμές. Δεν μετράνε σε
            καμία αναφορά εσόδων/ΦΠΑ· αυτή είναι η μόνη τους εικόνα. Όσα μετατράπηκαν σε φορολογικό χρεώθηκαν τελικά και
            φαίνονται μόνο στο πλαίσιο «Μετατράπηκαν». Η λίστα παραστατικών ανά έγγραφο είναι στην «Εξαγωγή CSV».
        </div>
    @endif
</x-filament-panels::page>
