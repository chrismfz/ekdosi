<div class="space-y-3 text-sm">
    <div class="text-gray-500 dark:text-gray-400">
        Ποια γραμμή του WHMCS τιμολογίου εκδίδεται σε ποιον δικαιούχο και με τι τύπο.
        @if ($isMulti)
            Πολλαπλοί δικαιούχοι → χρειάζεται <strong>διαχωρισμός</strong> (ένα παραστατικό ανά δικαιούχο).
        @endif
    </div>

    <table class="w-full text-left">
        <thead>
            <tr class="text-xs uppercase text-gray-500 dark:text-gray-400">
                <th class="py-1 pr-3">Γραμμή</th>
                <th class="py-1 pr-3">Δικαιούχος</th>
                <th class="py-1 pr-3">ΑΦΜ</th>
                <th class="py-1 pr-3">ekdosi</th>
                <th class="py-1">Τύπος</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr class="border-t border-gray-100 dark:border-gray-700">
                    <td class="py-1.5 pr-3">{{ $row['line'] }}</td>
                    <td class="py-1.5 pr-3">{{ $row['who'] }}</td>
                    <td class="py-1.5 pr-3 font-mono text-xs">{{ $row['afm'] !== '' ? $row['afm'] : '—' }}</td>
                    <td class="py-1.5 pr-3">
                        @if ($row['ekdosi_url'])
                            <a href="{{ $row['ekdosi_url'] }}" target="_blank" rel="noopener"
                               class="text-primary-600 hover:underline dark:text-primary-400">
                                Καρτέλα →
                            </a>
                        @elseif ($row['routed'])
                            <x-filament::badge color="warning" size="sm">Δεν υπάρχει</x-filament::badge>
                        @else
                            <span class="text-gray-400">—</span>
                        @endif
                    </td>
                    <td class="py-1.5">
                        <x-filament::badge :color="$row['receipt'] ? 'gray' : 'info'" size="sm">
                            {{ $row['receipt'] ? 'Απόδειξη' : 'Τιμολόγιο' }}
                        </x-filament::badge>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" class="py-2 text-gray-500 dark:text-gray-400">
                        Δεν βρέθηκε δρομολόγηση για αυτή την εγγραφή.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>

    @if (($missingCount ?? 0) > 0)
        <div class="rounded-md bg-warning-50 p-2 text-xs text-warning-700 dark:bg-warning-500/10 dark:text-warning-400">
            {{ $missingCount }} {{ $missingCount === 1 ? 'δικαιούχος δεν υπάρχει' : 'δικαιούχοι δεν υπάρχουν' }} ακόμη ως πελάτης στο ekdosi.
            Κλείσε αυτό και πάτα <strong>«Ενέργειες» → «Εισαγωγή τρίτων (ΑΑΔΕ)»</strong> για να δημιουργηθούν
            (στοιχεία από ΑΑΔΕ, με το email του τρίτου αν δόθηκε).
        </div>
    @endif

    @if ($isMulti)
        <div class="text-xs text-gray-500 dark:text-gray-400">
            Κλείσε αυτό και πάτα <strong>«Διαχωρισμός σε προσχέδια»</strong> στη γραμμή για να
            δημιουργηθεί ένα προσχέδιο ανά δικαιούχο.
        </div>
    @endif
</div>
