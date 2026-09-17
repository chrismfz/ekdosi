<x-filament-panels::page>
    <div class="text-sm text-gray-500 dark:text-gray-400 mb-4 leading-relaxed">
        Δήλωσε <strong>τι είναι</strong> κάθε ομάδα προϊόντων WHMCS (π.χ. «Web Hosting → υπηρεσία»).
        Οι γραμμές τιμολογίων που ανήκουν σε αυτή την ομάδα θα ταξινομούνται αυτόματα στην αντίστοιχη
        κατηγορία εσόδων (§8.6) της ΑΑΔΕ — και τα <strong>νέα πακέτα</strong> της ομάδας κληρονομούν την
        επιλογή, χωρίς να τα ξαναπερνάς. Το ποσό/η περιγραφή μένουν πάντα όπως στο WHMCS.
        Πάτησε «Άντληση προϊόντων WHMCS», όρισε ανά ομάδα, και «Αποθήκευση».
        Η στήλη <strong>«Κατηγορία ekdosi»</strong> (προαιρετική) ορίζει σε ποια δική σου κατηγορία
        προϊόντος μετράει η ομάδα στην αναφορά «Έσοδα ανά κατηγορία» — αποθηκεύεται μαζί με τη
        §8.6 (όρισε και τις δύο στην ίδια γραμμή). Ισχύει για <strong>νέα</strong> παραστατικά· τα
        παλιά μπαίνουν σε επόμενο βήμα.
    </div>

    @if (! $fetched)
        <x-filament::section>
            <div class="text-sm text-gray-500 dark:text-gray-400">
                Πάτησε <strong>«Άντληση προϊόντων WHMCS»</strong> για να φορτώσεις τις ομάδες προϊόντων.
                @if (count($choice) > 0)
                    (Υπάρχουν ήδη {{ count(array_filter($choice)) }} αποθηκευμένες αντιστοιχίσεις.)
                @endif
            </div>
        </x-filament::section>
    @else
        <x-filament::section>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs uppercase text-gray-500 dark:text-gray-400">
                            <th class="py-2 pr-4">Ομάδα προϊόντων (WHMCS)</th>
                            <th class="py-2 pr-4">Πακέτα</th>
                            <th class="py-2 pr-4">Κατηγορία εσόδων (§8.6)</th>
                            <th class="py-2">Κατηγορία ekdosi (αναφορές)</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                        @foreach ($groups as $g)
                            @php $gid = (string) $g['gid']; $unmapped = empty($choice[$gid] ?? ''); @endphp
                            <tr @class(['bg-warning-50 dark:bg-warning-950/40' => $unmapped])>
                                <td class="py-2 pr-4 align-top font-semibold text-gray-800 dark:text-gray-100">
                                    {{ $g['name'] !== '' ? $g['name'] : ('Ομάδα #'.$g['gid']) }}
                                </td>
                                <td class="py-2 pr-4 align-top text-gray-500 dark:text-gray-400">
                                    {{ \Illuminate\Support\Str::limit(implode(', ', $g['packages']), 120) }}
                                </td>
                                <td class="py-2 pr-4 align-top">
                                    <select wire:model="choice.{{ $gid }}"
                                        class="rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 px-2 py-1 text-sm">
                                        @foreach ($this->bucketOptions() as $value => $label)
                                            <option value="{{ $value }}">{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td class="py-2 align-top">
                                    <select wire:model="categoryChoice.{{ $gid }}"
                                        class="rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 px-2 py-1 text-sm">
                                        @foreach ($this->categoryOptions() as $value => $label)
                                            <option value="{{ $value }}">{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="text-xs text-gray-400 dark:text-gray-500 mt-3 leading-relaxed">
                Οι κίτρινες γραμμές δεν έχουν οριστεί ακόμη. Ό,τι αφήσεις «δεν έχει οριστεί» ταξινομείται
                από τον τύπο του παραστατικού (οι υπηρεσίες → category1_3). Για ξεχωριστό πακέτο εντός ομάδας,
                η αντιστοίχιση ανά προϊόν έρχεται σε επόμενη έκδοση.
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
