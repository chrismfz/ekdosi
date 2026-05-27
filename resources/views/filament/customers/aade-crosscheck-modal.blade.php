@php
    /** @var \App\Models\Customer $customer */
    /** @var array $result */
    $error = $result['error'] ?? null;
    $diffs = $result['diffs'] ?? [];
    $aadeRecord = $result['record'] ?? null;

    $labels = [
        'name'       => 'Επωνυμία',
        'tax_office' => 'ΔΟΥ',
        'address1'   => 'Διεύθυνση',
        'city'       => 'Πόλη',
        'postcode'   => 'Τ.Κ.',
        'occupation' => 'Δραστηριότητα',
    ];
@endphp

@if ($error)
    <div class="rounded-lg bg-danger-50 dark:bg-danger-950/40 p-4 text-sm text-danger-700 dark:text-danger-200">
        <strong>Δεν ήταν δυνατή η ανάκτηση από ΑΑΔΕ:</strong>
        <div class="mt-1">{{ $error }}</div>
    </div>
@elseif ($aadeRecord && ! $aadeRecord->active)
    <div class="rounded-lg bg-warning-50 dark:bg-warning-950/40 p-4 text-sm text-warning-700 dark:text-warning-200">
        <strong>Προσοχή:</strong> Η ΑΑΔΕ δείχνει αυτόν τον ΑΦΜ ως
        <em>{{ $aadeRecord->statusDescr ?: 'ανενεργό' }}</em>.
        Πιθανώς ο πελάτης έχει σταματήσει τη δραστηριότητά του.
    </div>
@endif

@if ($aadeRecord)
    @if (empty($diffs))
        <div class="rounded-lg bg-success-50 dark:bg-success-950/40 p-4 text-sm text-success-700 dark:text-success-300">
            ✓ Όλα τα στοιχεία ταυτίζονται με την ΑΑΔΕ. Δεν χρειάζεται ενημέρωση.
        </div>
    @else
        <div class="rounded-lg border border-warning-200 dark:border-warning-800 p-4">
            <div class="text-sm font-semibold mb-3">
                Εντοπίστηκαν {{ count($diffs) }} διαφορά/ές μεταξύ των στοιχείων που έχουμε και της ΑΑΔΕ.
            </div>
            <table class="min-w-full text-sm">
                <thead class="text-xs text-gray-500 uppercase border-b border-gray-200 dark:border-gray-700">
                    <tr>
                        <th class="px-3 py-2 text-left">Πεδίο</th>
                        <th class="px-3 py-2 text-left">Στοιχείο μας</th>
                        <th class="px-3 py-2 text-left">Στοιχείο ΑΑΔΕ</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                    @foreach ($diffs as $field => $pair)
                        <tr>
                            <td class="px-3 py-2 font-semibold">{{ $labels[$field] ?? $field }}</td>
                            <td class="px-3 py-2 text-gray-600 dark:text-gray-400">
                                {{ $pair['stored'] !== '' ? $pair['stored'] : '—' }}
                            </td>
                            <td class="px-3 py-2 text-success-700 dark:text-success-300 font-medium">
                                {{ $pair['aade'] !== '' ? $pair['aade'] : '—' }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <div class="mt-3 text-xs text-gray-500">
                Πατήστε <strong>Ενημέρωση πελάτη με στοιχεία ΑΑΔΕ</strong> για να εφαρμοστούν τα δεξιά στοιχεία στον πελάτη.
                Η ΑΑΔΕ είναι η αυθεντική πηγή αλήθειας — αν αυτό που έχουμε διαφέρει, συνήθως πρέπει να επικαιροποιήσουμε.
            </div>
        </div>
    @endif

    @if (count($aadeRecord->activities) > 0)
        <div class="mt-4 text-xs text-gray-500">
            <details>
                <summary class="cursor-pointer">Όλες οι δραστηριότητες του ΑΦΜ ({{ count($aadeRecord->activities) }})</summary>
                <ul class="mt-2 list-disc list-inside">
                    @foreach ($aadeRecord->activities as $act)
                        <li>
                            <code>{{ $act['code'] ?? '?' }}</code> — {{ $act['descr'] ?? '' }}
                            @if (! empty($act['primary'])) <span class="text-success-600">(κύρια)</span> @endif
                        </li>
                    @endforeach
                </ul>
            </details>
        </div>
    @endif
@endif
