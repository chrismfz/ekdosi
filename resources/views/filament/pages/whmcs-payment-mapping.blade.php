<x-filament-panels::page>
    <div class="text-sm text-gray-500 dark:text-gray-400 mb-4 leading-relaxed">
        Δήλωσε με <strong>ποιον τρόπο πληρωμής</strong> εκδίδεται ένα <strong>πληρωμένο</strong> τιμολόγιο
        WHMCS ανάλογα με το <strong>gateway</strong> που πληρώθηκε (π.χ. «Τραπεζική κατάθεση → Κατάθεση»,
        «Stripe → Κάρτα»). Ο τρόπος πληρωμής του ekdosi κουβαλά τον <strong>κωδικό §8.12</strong>, οπότε ένα
        τιμολόγιο που πληρώθηκε με κάρτα/κατάθεση δεν θα δηλώνεται πια ως «Μετρητά». Προσφέρονται μόνο
        <strong>εξοφλημένοι-στην-έκδοση</strong> τρόποι (ένα πληρωμένο τιμολόγιο δεν είναι «επί πιστώσει»).
        Ό,τι δεν αντιστοιχίσεις — ή ένα ΑΠΛΗΡΩΤΟ τιμολόγιο — παίρνει τον τρόπο του τύπου παραστατικού.
        Πάτησε «Άντληση τρόπων πληρωμής WHMCS», όρισε ανά gateway, και «Αποθήκευση».
    </div>

    @if (! $fetched)
        <x-filament::section>
            <div class="text-sm text-gray-500 dark:text-gray-400">
                Πάτησε <strong>«Άντληση τρόπων πληρωμής WHMCS»</strong> για να φορτώσεις τα gateways.
                @if (count($choice) > 0)
                    (Υπάρχουν ήδη {{ count(array_filter($choice)) }} αποθηκευμένες αντιστοιχίσεις.)
                @endif
            </div>
        </x-filament::section>
    @else
        @php $pmOptions = $this->paymentMethodOptions(); @endphp
        <x-filament::section>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs uppercase text-gray-500 dark:text-gray-400">
                            <th class="py-2 pr-4">Τρόπος πληρωμής (WHMCS gateway)</th>
                            <th class="py-2">Τρόπος πληρωμής ekdosi (§8.12)</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                        @foreach ($gateways as $g)
                            @php $gw = $g['gateway']; $unmapped = empty($choice[$gw] ?? ''); @endphp
                            <tr @class(['bg-warning-50 dark:bg-warning-950/40' => $unmapped])>
                                <td class="py-2 pr-4 align-top font-semibold text-gray-800 dark:text-gray-100">
                                    {{ $g['name'] !== '' ? $g['name'] : $gw }}
                                    <div class="text-xs font-normal text-gray-400 dark:text-gray-500">{{ $gw }}</div>
                                </td>
                                <td class="py-2 align-top">
                                    <select wire:model="choice.{{ $gw }}"
                                        class="rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 px-2 py-1 text-sm">
                                        @foreach ($pmOptions as $value => $label)
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
                Οι κίτρινες γραμμές δεν έχουν οριστεί ακόμη. Ό,τι αφήσεις «κληρονομεί τον τύπο» παίρνει τον
                τρόπο πληρωμής του τύπου παραστατικού. Εμφανίζονται μόνο <strong>εξοφλημένοι-στην-έκδοση</strong>
                τρόποι <strong>με §8.12</strong>· αν λείπει ένας, όρισε τον §8.12 του στους «Τρόπους Πληρωμής»
                (ή τρέξε <code>mydata:backfill-config</code>).
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
