<x-filament-panels::page>
    @php $money = fn ($v) => '€ ' . number_format((float) $v, 2, ',', '.'); @endphp

    {{-- ΦΠΑ τριμήνου — the same authoritative snapshot the dashboard shows
         (RequestVatInfo, refreshed by the scheduler), surfaced here too. --}}
    @if ($vatQuarter)
        <x-filament::section>
            <x-slot name="heading">ΦΠΑ τριμήνου (από myDATA)</x-slot>
            <x-slot name="description">
                Εκροών {{ $money($vatQuarter['outputVat']) }} − εισροών {{ $money($vatQuarter['inputVat']) }}
                @if ($vatQuarter['fetchedAt']) · ενημερώθηκε {{ $vatQuarter['fetchedAt'] }} @endif
            </x-slot>
            <div class="flex items-baseline gap-3">
                <div @class([
                    'text-2xl font-bold',
                    'text-danger-600 dark:text-danger-400' => $vatQuarter['payable'],
                    'text-success-600 dark:text-success-400' => ! $vatQuarter['payable'],
                ])>{{ $money(abs($vatQuarter['netVat'])) }}</div>
                <div class="text-sm text-gray-500 dark:text-gray-400">
                    {{ $vatQuarter['payable'] ? 'Προς απόδοση' : 'Πιστωτικό υπόλοιπο' }}
                </div>
            </div>
        </x-filament::section>
    @endif

    @if (! $ran)
        <x-filament::section>
            <x-slot name="heading">Επισκόπηση Ε3</x-slot>
            <x-slot name="description">
                Αθροιστικά στοιχεία Ε3 (ανά τύπο/κατηγορία χαρακτηρισμού) όπως τα τηρεί το myDATA για το ΑΦΜ μας.
                Read-only — η σελίδα δεν τροποποιεί τίποτα.
            </x-slot>
            <p class="text-sm text-gray-500 dark:text-gray-400">Πατήστε «Λήψη Ε3 από myDATA» για ένα διάστημα.</p>
        </x-filament::section>
    @endif

    @if ($error)
        <x-filament::section>
            <div class="text-danger-600 dark:text-danger-400 font-medium">{{ $error }}</div>
        </x-filament::section>
    @endif

    @if ($ran && $result)
        @if ($fetchedAtHuman)
            <div class="text-xs text-gray-400 dark:text-gray-500">
                Αποθηκευμένο αποτέλεσμα · τελευταία ενημέρωση {{ $fetchedAtHuman }} — πατήστε ξανά «Λήψη Ε3 από myDATA» για ανανέωση.
            </div>
        @endif

        {{-- Summary: income and expense are shown SEPARATELY — summing them is
             meaningless (they're opposite sides of the Ε3). --}}
        <div class="grid grid-cols-2 gap-4 md:grid-cols-4">
            <x-filament::section>
                <div class="text-sm text-gray-500 dark:text-gray-400">Διάστημα</div>
                <div class="text-base font-semibold">{{ $result['from'] }} – {{ $result['to'] }}</div>
            </x-filament::section>
            <x-filament::section>
                <div class="text-sm text-gray-500 dark:text-gray-400">Εγγραφές</div>
                <div class="text-2xl font-bold">{{ $result['docCount'] }}</div>
            </x-filament::section>
            <x-filament::section>
                <div class="text-sm text-gray-500 dark:text-gray-400">Έσοδα (Ε3)</div>
                <div class="text-2xl font-bold text-success-600 dark:text-success-400">{{ $money($result['incomeTotal']) }}</div>
            </x-filament::section>
            <x-filament::section>
                <div class="text-sm text-gray-500 dark:text-gray-400">Έξοδα (Ε3)</div>
                <div class="text-2xl font-bold text-warning-600 dark:text-warning-400">{{ $money($result['expenseTotal']) }}</div>
            </x-filament::section>
        </div>

        @if (count($result['rows']) === 0)
            <x-filament::section>
                <div class="flex items-center gap-2 text-gray-500 dark:text-gray-400">
                    <x-filament::icon icon="heroicon-o-inbox" class="h-6 w-6" />
                    <span>Δεν επιστράφηκαν στοιχεία Ε3 για το διάστημα.</span>
                </div>
            </x-filament::section>
        @else
            {{-- A short legend so the table reads to a human eye. --}}
            <x-filament::section>
                <div class="flex items-start gap-2 text-sm text-gray-600 dark:text-gray-300">
                    <x-filament::icon icon="heroicon-o-information-circle" class="mt-0.5 h-5 w-5 text-gray-400" />
                    <span>
                        Τα αθροιστικά Ε3 που τηρεί η ΑΑΔΕ για το ΑΦΜ μας. Τα <strong>έσοδα</strong> (κωδικοί E3_56x)
                        και τα <strong>έξοδα</strong> (E3_58x) εμφανίζονται χωριστά — δεν αθροίζονται μεταξύ τους.
                        Το «<span class="font-mono">(-) / (+)</span>» στις κατηγορίες είναι η σήμανση προσήμου της ΑΑΔΕ.
                    </span>
                </div>
            </x-filament::section>

            @php
                $groups = [
                    ['dir' => 'income',  'title' => 'Έσοδα',               'subtitle' => 'Χαρακτηρισμοί εσόδων (E3_56x)',                 'total' => $result['incomeTotal'],  'color' => 'success'],
                    ['dir' => 'expense', 'title' => 'Έξοδα',               'subtitle' => 'Χαρακτηρισμοί εξόδων (E3_58x)',                 'total' => $result['expenseTotal'], 'color' => 'warning'],
                    ['dir' => 'unknown', 'title' => 'Λοιπά / αταξινόμητα', 'subtitle' => 'Κωδικοί που δεν αναγνωρίζονται ως έσοδο/έξοδο', 'total' => $result['unknownTotal'], 'color' => 'gray'],
                ];
            @endphp

            @foreach ($groups as $g)
                @php $groupRows = array_values(array_filter($result['rows'], fn ($r) => $r['direction'] === $g['dir'])); @endphp
                @if (count($groupRows) > 0)
                    <x-filament::section>
                        <x-slot name="heading">
                            <span class="flex items-center gap-2">
                                {{ $g['title'] }}
                                <x-filament::badge :color="$g['color']">{{ $money($g['total']) }}</x-filament::badge>
                            </span>
                        </x-slot>
                        <x-slot name="description">{{ $g['subtitle'] }}</x-slot>

                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead class="text-left text-gray-500 dark:text-gray-400">
                                    <tr class="border-b border-gray-200 dark:border-white/10">
                                        <th class="py-2 pr-4">Τύπος (E3)</th>
                                        <th class="py-2 pr-4">Κατηγορία</th>
                                        <th class="py-2 pr-4 text-right">Πλήθος</th>
                                        <th class="py-2 text-right">Αξία</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($groupRows as $row)
                                        <tr class="border-b border-gray-100 dark:border-white/5">
                                            <td class="py-2 pr-4">
                                                <span class="font-mono text-xs text-gray-500 dark:text-gray-400">{{ $row['classType'] }}</span>
                                                @if ($row['typeLabel'])
                                                    <div>{{ $row['typeLabel'] }}</div>
                                                @endif
                                            </td>
                                            <td class="py-2 pr-4">
                                                @if ($row['classCategory'])
                                                    <span class="font-mono text-xs text-gray-500 dark:text-gray-400">{{ $row['classCategory'] }}</span>
                                                    @if ($row['categoryLabel'])
                                                        <div>{{ $row['categoryLabel'] }}</div>
                                                    @endif
                                                @else
                                                    <span class="text-gray-400">—</span>
                                                @endif
                                            </td>
                                            <td class="py-2 pr-4 text-right align-top">{{ $row['count'] }}</td>
                                            <td class="py-2 text-right whitespace-nowrap align-top">{{ $money($row['value']) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                                <tfoot>
                                    <tr class="border-t-2 border-gray-200 dark:border-white/10 font-semibold">
                                        <td class="py-2 pr-4" colspan="3">Σύνολο — {{ $g['title'] }}</td>
                                        <td class="py-2 text-right whitespace-nowrap">{{ $money($g['total']) }}</td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </x-filament::section>
                @endif
            @endforeach
        @endif
    @endif
</x-filament-panels::page>
