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
                <th class="py-1">Τύπος</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr class="border-t border-gray-100 dark:border-gray-700">
                    <td class="py-1.5 pr-3">{{ $row['line'] }}</td>
                    <td class="py-1.5 pr-3">
                        {{ $row['who'] }}
                        @unless ($row['routed'])
                            <span class="text-gray-400">·</span>
                        @endunless
                    </td>
                    <td class="py-1.5 pr-3 font-mono text-xs">{{ $row['afm'] !== '' ? $row['afm'] : '—' }}</td>
                    <td class="py-1.5">
                        <x-filament::badge :color="$row['receipt'] ? 'gray' : 'info'" size="sm">
                            {{ $row['receipt'] ? 'Απόδειξη' : 'Τιμολόγιο' }}
                        </x-filament::badge>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="4" class="py-2 text-gray-500 dark:text-gray-400">
                        Δεν βρέθηκε δρομολόγηση για αυτή την εγγραφή.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>

    @if ($isMulti)
        <div class="text-xs text-gray-500 dark:text-gray-400">
            Κλείσε αυτό και πάτα <strong>«Διαχωρισμός σε προσχέδια»</strong> στη γραμμή για να
            δημιουργηθεί ένα προσχέδιο ανά δικαιούχο.
        </div>
    @endif
</div>
