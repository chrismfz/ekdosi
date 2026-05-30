<x-filament-panels::page>
    @if (! $ran)
        <x-filament::section>
            <x-slot name="heading">Ζωντανός έλεγχος myDATA</x-slot>
            <x-slot name="description">
                Δύο κατευθύνσεις, ίδια πηγή (RequestTransmittedDocs) — η σελίδα δεν τροποποιεί τίποτα.
            </x-slot>
            <ul class="space-y-2 text-sm text-gray-600 dark:text-gray-300">
                <li class="flex items-start gap-2">
                    <x-filament::icon icon="heroicon-o-clipboard-document-check" class="mt-0.5 h-5 w-5 text-primary-500" />
                    <span>
                        <strong>Έλεγχος δικών μας στο myDATA</strong> — τα παραστατικά που εκδώσαμε εμείς:
                        υπάρχουν όλα στο myDATA και συμφωνούν οι καταστάσεις/ακυρώσεις;
                    </span>
                </li>
                <li class="flex items-start gap-2">
                    <x-filament::icon icon="heroicon-o-cloud-arrow-down" class="mt-0.5 h-5 w-5 text-warning-500" />
                    <span>
                        <strong>Αδέσποτα από myDATA</strong> — η ανάποδη ματιά: παραστατικά που έχει το
                        myDATA για το ΑΦΜ μας αλλά <em>δεν</em> υπάρχουν στο ekdosi (π.χ. εκδόθηκαν από
                        e-τιμολόγιο ΑΑΔΕ ή άλλο πρόγραμμα).
                    </span>
                </li>
            </ul>
        </x-filament::section>
    @endif

    @if ($error)
        <x-filament::section>
            <div class="text-danger-600 dark:text-danger-400 font-medium">
                {{ $error }}
            </div>
        </x-filament::section>
    @endif

    @if ($ran && $result)
        @php $mode = $resultMode ?? 'compare'; @endphp

        @if ($fetchedAtHuman)
            <div class="text-xs text-gray-400 dark:text-gray-500">
                Αποθηκευμένο αποτέλεσμα · τελευταία ενημέρωση {{ $fetchedAtHuman }} — πατήστε ξανά «Έλεγχος»/«Αδέσποτα» για ανανέωση.
            </div>
        @endif

        @if ($mode === 'inbound')
            {{-- ============================================================
                 Direction 2 — myDATA → US ("αδέσποτα").
                 Same fetch, AADE-centric framing: of everything myDATA holds,
                 which is linked to a local invoice and which is orphaned.
                 ============================================================ --}}
            @php
                $orphans = $result['missingLocally'];
                $linked = array_merge($result['matched'], $result['stateMismatch']);

                // Split αδέσποτα by economic bucket so payroll/expenses don't
                // masquerade as missed sales. 'income' is the only actionable
                // one on THIS console; the rest are informational.
                $orphanIncome = array_values(array_filter($orphans, fn ($r) => ($r['bucket'] ?? 'other') === 'income'));
                $orphanExpense = array_values(array_filter($orphans, fn ($r) => ($r['bucket'] ?? 'other') === 'expense'));
                $orphanOther = array_values(array_filter($orphans, fn ($r) => ($r['bucket'] ?? 'other') === 'other'));
            @endphp

            <div class="grid grid-cols-2 gap-4 md:grid-cols-4">
                <x-filament::section>
                    <div class="text-sm text-gray-500 dark:text-gray-400">Διάστημα</div>
                    <div class="text-base font-semibold">{{ $result['from'] }} – {{ $result['to'] }}</div>
                </x-filament::section>
                <x-filament::section>
                    <div class="text-sm text-gray-500 dark:text-gray-400">Στο myDATA</div>
                    <div class="text-2xl font-bold">{{ $result['aadeTotal'] }}</div>
                </x-filament::section>
                <x-filament::section>
                    <div class="text-sm text-gray-500 dark:text-gray-400">Συνδεδεμένα</div>
                    <div class="text-2xl font-bold text-success-600 dark:text-success-400">{{ count($linked) }}</div>
                </x-filament::section>
                <x-filament::section>
                    <div class="text-sm text-gray-500 dark:text-gray-400">Αδέσποτα πωλήσεων</div>
                    <div @class([
                        'text-2xl font-bold',
                        'text-success-600 dark:text-success-400' => count($orphanIncome) === 0,
                        'text-warning-600 dark:text-warning-400' => count($orphanIncome) > 0,
                    ])>{{ count($orphanIncome) }}</div>
                    @if (count($orphanExpense) + count($orphanOther) > 0)
                        <div class="mt-1 text-xs text-gray-400 dark:text-gray-500">
                            + {{ count($orphanExpense) + count($orphanOther) }} λοιπά (έξοδα/εγγραφές)
                        </div>
                    @endif
                </x-filament::section>
            </div>

            @if ($result['aadeTotal'] === 0)
                <x-filament::section>
                    <div class="flex items-center gap-2 text-gray-500 dark:text-gray-400">
                        <x-filament::icon icon="heroicon-o-inbox" class="h-6 w-6" />
                        <span>Το myDATA δεν επέστρεψε παραστατικά για το διάστημα.</span>
                    </div>
                </x-filament::section>
            @elseif (count($orphans) === 0)
                <x-filament::section>
                    <div class="flex items-center gap-2 text-success-600 dark:text-success-400">
                        <x-filament::icon icon="heroicon-o-check-circle" class="h-6 w-6" />
                        <span class="font-medium">Δεν βρέθηκαν αδέσποτα — όλα όσα έχει το myDATA είναι συνδεδεμένα με τοπικό παραστατικό.</span>
                    </div>
                </x-filament::section>
            @endif

            {{-- Bucket 1 — INCOME orphans: the only actionable list here.
                 "Did I forget to file a sale that's already at myDATA?" --}}
            @if (count($orphanIncome) > 0)
                <x-filament::section>
                    <x-slot name="heading">
                        <span class="flex items-center gap-2">
                            <x-filament::icon icon="heroicon-o-cloud-arrow-down" class="h-5 w-5 text-warning-500" />
                            Αδέσποτα πωλήσεων (έσοδα, όχι στο ekdosi)
                            <x-filament::badge color="warning">{{ count($orphanIncome) }}</x-filament::badge>
                        </span>
                    </x-slot>
                    <x-slot name="description">
                        Τιμολόγια/αποδείξεις εσόδων που υπάρχουν στο myDATA για το ΑΦΜ μας αλλά δεν έχουν
                        τοπική εγγραφή — εκδόθηκαν πιθανότατα από e-τιμολόγιο ή άλλο πρόγραμμα.
                        Καταχωρίστε τα στο ekdosi ή αγνοήστε.
                    </x-slot>

                    @include('filament.pages.partials.reconciliation-table', [
                        'rows' => $orphanIncome,
                        'columns' => ['mark', 'type', 'issuedAt', 'counterpart', 'gross', 'mydataState'],
                    ])
                </x-filament::section>
            @endif

            {{-- Bucket 2 — SUPPLIER EXPENSES: not sales. Belong to the Έξοδα
                 console (RequestDocs + 1-click import live there). --}}
            @if (count($orphanExpense) > 0)
                <x-filament::section :collapsible="true" :collapsed="true">
                    <x-slot name="heading">
                        <span class="flex items-center gap-2">
                            <x-filament::icon icon="heroicon-o-receipt-percent" class="h-5 w-5 text-gray-400" />
                            Έξοδα προμηθευτών (όχι πωλήσεις)
                            <x-filament::badge color="gray">{{ count($orphanExpense) }}</x-filament::badge>
                        </span>
                    </x-slot>
                    <x-slot name="description">
                        Παραστατικά εξόδων/εισροών (ενδοκοινοτικά, τρίτων χωρών, αγορές λιανικής).
                        <strong>Δεν είναι δικές σου πωλήσεις.</strong> Η καταχώρισή τους γίνεται στην
                        «Κονσόλα myDATA — Έξοδα», όπου υπάρχει αντιστοίχιση προμηθευτή και import με ένα κλικ.
                    </x-slot>

                    @include('filament.pages.partials.reconciliation-table', [
                        'rows' => $orphanExpense,
                        'columns' => ['mark', 'type', 'issuedAt', 'gross', 'mydataState'],
                    ])
                </x-filament::section>
            @endif

            {{-- Bucket 3 — OTHER self-declared entries: payroll, fixed assets,
                 ΕΦΚΑ, accounting adjustments. Informational; the accountant's
                 territory, NOT something ekdosi issues. --}}
            @if (count($orphanOther) > 0)
                <x-filament::section :collapsible="true" :collapsed="true">
                    <x-slot name="heading">
                        <span class="flex items-center gap-2">
                            <x-filament::icon icon="heroicon-o-document-text" class="h-5 w-5 text-gray-400" />
                            Λοιπές δικές σου εγγραφές (μισθοδοσία, πάγια, τακτοποιήσεις)
                            <x-filament::badge color="gray">{{ count($orphanOther) }}</x-filament::badge>
                        </span>
                    </x-slot>
                    <x-slot name="description">
                        Αυτο-δηλούμενες λογιστικές εγγραφές (π.χ. <strong>μισθοδοσία 17.1</strong>, αποσβέσεις,
                        ΕΦΚΑ). <strong>Δεν είναι πωλήσεις</strong> — υποβάλλονται συνήθως από τον λογιστή ή
                        άλλο πρόγραμμα. Εμφανίζονται εδώ μόνο ενημερωτικά.
                    </x-slot>

                    @include('filament.pages.partials.reconciliation-table', [
                        'rows' => $orphanOther,
                        'columns' => ['mark', 'type', 'issuedAt', 'gross', 'mydataState'],
                    ])
                </x-filament::section>
            @endif

            {{-- Linked (collapsed by default) --}}
            @if (count($linked) > 0)
                <x-filament::section :collapsible="true" :collapsed="true">
                    <x-slot name="heading">
                        <span class="flex items-center gap-2">
                            <x-filament::icon icon="heroicon-o-link" class="h-5 w-5 text-success-500" />
                            Συνδεδεμένα με τοπικό παραστατικό
                            <x-filament::badge color="success">{{ count($linked) }}</x-filament::badge>
                        </span>
                    </x-slot>

                    @include('filament.pages.partials.reconciliation-table', [
                        'rows' => $linked,
                        'columns' => ['invcode', 'mark', 'issuedAt', 'counterpart', 'gross', 'linkStatus', 'open'],
                    ])
                </x-filament::section>
            @endif
        @else
            {{-- ============================================================
                 Direction 1 — OUR records → myDATA (the original view).
                 ============================================================ --}}
            {{-- Summary cards --}}
            <div class="grid grid-cols-2 gap-4 md:grid-cols-4">
                <x-filament::section>
                    <div class="text-sm text-gray-500 dark:text-gray-400">Διάστημα</div>
                    <div class="text-base font-semibold">{{ $result['from'] }} – {{ $result['to'] }}</div>
                </x-filament::section>
                <x-filament::section>
                    <div class="text-sm text-gray-500 dark:text-gray-400">Στο AADE</div>
                    <div class="text-2xl font-bold">{{ $result['aadeTotal'] }}</div>
                </x-filament::section>
                <x-filament::section>
                    <div class="text-sm text-gray-500 dark:text-gray-400">Τοπικά (υποβληθέντα)</div>
                    <div class="text-2xl font-bold">{{ $result['localTotal'] }}</div>
                </x-filament::section>
                <x-filament::section>
                    <div class="text-sm text-gray-500 dark:text-gray-400">Ασυμφωνίες</div>
                    <div @class([
                        'text-2xl font-bold',
                        'text-success-600 dark:text-success-400' => $result['discrepancyCount'] === 0,
                        'text-warning-600 dark:text-warning-400' => $result['discrepancyCount'] > 0,
                    ])>{{ $result['discrepancyCount'] }}</div>
                </x-filament::section>
            </div>

            @if ($result['discrepancyCount'] === 0)
                <x-filament::section>
                    <div class="flex items-center gap-2 text-success-600 dark:text-success-400">
                        <x-filament::icon icon="heroicon-o-check-circle" class="h-6 w-6" />
                        <span class="font-medium">Όλα τα τοπικά παραστατικά συμφωνούν με το AADE.</span>
                    </div>
                </x-filament::section>
            @endif

            {{-- Discrepancy buckets. NOTE: missingLocally (αδέσποτα) is NOT
                 rendered here as a full table — it has its own dedicated
                 "Αδέσποτα από myDATA" direction. We surface only a slim
                 pointer below so the Ασυμφωνίες count still reconciles. --}}
            @foreach ([
                ['key' => 'stateMismatch', 'title' => 'Ασυμφωνία κατάστασης', 'color' => 'warning', 'icon' => 'heroicon-o-exclamation-triangle'],
                ['key' => 'missingAtAade', 'title' => 'Λείπουν από το AADE', 'color' => 'danger', 'icon' => 'heroicon-o-x-circle'],
                ['key' => 'duplicateLocal', 'title' => 'Διπλά ΜΑΡΚ τοπικά', 'color' => 'danger', 'icon' => 'heroicon-o-document-duplicate'],
            ] as $bucket)
                @if (count($result[$bucket['key']]) > 0)
                    <x-filament::section :collapsible="true">
                        <x-slot name="heading">
                            <span class="flex items-center gap-2">
                                <x-filament::icon :icon="$bucket['icon']" @class([
                                    'h-5 w-5',
                                    'text-warning-500' => $bucket['color'] === 'warning',
                                    'text-danger-500' => $bucket['color'] === 'danger',
                                ]) />
                                {{ $bucket['title'] }}
                                <x-filament::badge :color="$bucket['color']">{{ count($result[$bucket['key']]) }}</x-filament::badge>
                            </span>
                        </x-slot>

                        @include('filament.pages.partials.reconciliation-table', [
                            'rows' => $result[$bucket['key']],
                            'columns' => ['invcode', 'mark', 'issuedAt', 'counterpart', 'gross', 'localState', 'aadeState', 'problem', 'open'],
                        ])
                    </x-filament::section>
                @endif
            @endforeach

            {{-- αδέσποτα: slim pointer to the dedicated direction (avoids
                 showing the same list twice). Headline the INCOME orphans —
                 the rest (έξοδα/εγγραφές) are informational. --}}
            @php
                $missIncome = count(array_filter($result['missingLocally'], fn ($r) => ($r['bucket'] ?? 'other') === 'income'));
                $missRest = count($result['missingLocally']) - $missIncome;
            @endphp
            @if (count($result['missingLocally']) > 0)
                <x-filament::section>
                    <div class="flex flex-wrap items-center gap-2 text-sm text-gray-600 dark:text-gray-300">
                        <x-filament::icon icon="heroicon-o-question-mark-circle" class="h-5 w-5 text-warning-500" />
                        <span>
                            <strong>{{ $missIncome }}</strong> αδέσποτα πωλήσεων
                            @if ($missRest > 0)
                                <span class="text-gray-500 dark:text-gray-400">(+ {{ $missRest }} έξοδα/εγγραφές)</span>
                            @endif
                            στο myDATA, χωρίς τοπική εγγραφή.
                        </span>
                        <span class="text-gray-500 dark:text-gray-400">
                            Αναλυτικά στη λειτουργία «Αδέσποτα από myDATA».
                        </span>
                    </div>
                </x-filament::section>
            @endif

            {{-- Matched (collapsed by default) --}}
            @if (count($result['matched']) > 0)
                <x-filament::section :collapsible="true" :collapsed="true">
                    <x-slot name="heading">
                        <span class="flex items-center gap-2">
                            <x-filament::icon icon="heroicon-o-check-circle" class="h-5 w-5 text-success-500" />
                            Συμφωνούν
                            <x-filament::badge color="success">{{ count($result['matched']) }}</x-filament::badge>
                        </span>
                    </x-slot>

                    @include('filament.pages.partials.reconciliation-table', [
                        'rows' => $result['matched'],
                        'columns' => ['invcode', 'mark', 'issuedAt', 'counterpart', 'gross', 'state', 'open'],
                    ])
                </x-filament::section>
            @endif
        @endif
    @endif
</x-filament-panels::page>
