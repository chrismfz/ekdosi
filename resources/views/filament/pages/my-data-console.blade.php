<x-filament-panels::page>
    @if (! $ran)
        <x-filament::section>
            <x-slot name="heading">Ζωντανός έλεγχος myDATA</x-slot>
            <x-slot name="description">
                Ένα κουμπί «Έλεγχος myDATA» δείχνει και τις δύο κατευθύνσεις (ίδια πηγή, RequestTransmittedDocs) — η σελίδα δεν τροποποιεί τίποτα.
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
        @php
            // ONE fetch, BOTH directions shown together.
            $orphans = $result['missingLocally'];
            $orphanIncome = array_values(array_filter($orphans, fn ($r) => ($r['bucket'] ?? 'other') === 'income'));
            $orphanExpense = array_values(array_filter($orphans, fn ($r) => ($r['bucket'] ?? 'other') === 'expense'));
            $orphanOther = array_values(array_filter($orphans, fn ($r) => ($r['bucket'] ?? 'other') === 'other'));
        @endphp

        @if ($fetchedAtHuman)
            <div class="text-xs text-gray-400 dark:text-gray-500">
                Αποθηκευμένο αποτέλεσμα · τελευταία ενημέρωση {{ $fetchedAtHuman }} — πατήστε ξανά «Έλεγχος myDATA» για ανανέωση.
            </div>
        @endif

        {{-- Unified summary cards (both directions) --}}
        <div class="grid grid-cols-2 gap-4 md:grid-cols-5">
            <x-filament::section>
                <div class="text-sm text-gray-500 dark:text-gray-400">Διάστημα</div>
                <div class="text-base font-semibold">{{ $result['from'] }} – {{ $result['to'] }}</div>
            </x-filament::section>
            <x-filament::section>
                <div class="text-sm text-gray-500 dark:text-gray-400">Στο myDATA</div>
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

        {{-- ============================================================
             Direction 1 — ΤΑ ΔΙΚΑ ΜΑΣ → myDATA: υπάρχουν & συμφωνούν;
             ============================================================ --}}
        <div class="flex items-center gap-2 pt-2 text-sm font-semibold text-gray-700 dark:text-gray-200">
            <x-filament::icon icon="heroicon-o-clipboard-document-check" class="h-5 w-5 text-primary-500" />
            Τα δικά μας στο myDATA
        </div>

        @php
            // Only on a SANDBOX connection is an imported (production-MARK) «missing
            // at AADE» noise — there the MARK can't be returned. In PRODUCTION an
            // imported MARK was filed to the SAME channel, so its absence is a REAL
            // concern and stays in the danger bucket.
            $sandbox = $result['sandbox'] ?? false;
            $missingNoise = $sandbox
                ? array_values(array_filter($result['missingAtAade'], fn ($r) => ! empty($r['imported'])))
                : [];
            $missingReal = $sandbox
                ? array_values(array_filter($result['missingAtAade'], fn ($r) => empty($r['imported'])))
                : $result['missingAtAade'];
        @endphp

        {{-- Sandbox caveat: explains why imported (production) MARKs show as missing. --}}
        @if (count($missingNoise) > 0)
            <x-filament::section>
                <div class="flex items-start gap-2 text-sm text-gray-600 dark:text-gray-300">
                    <x-filament::icon icon="heroicon-o-beaker" class="mt-0.5 h-5 w-5 shrink-0 text-warning-500" />
                    <span>
                        Συνδεδεμένο σε <strong>δοκιμαστικό (sandbox)</strong> myDATA. Τα {{ count($missingNoise) }}
                        <strong>εισαγμένα</strong> παραστατικά (ΜΑΡΚ παραγωγής από το προηγούμενο σύστημα) δεν
                        επιστρέφονται από το sandbox — <em>δεν</em> είναι πραγματική ασυμφωνία. Δες τα στο «Εισαγμένα» πιο κάτω.
                    </span>
                </div>
            </x-filament::section>
        @endif

        @if ($result['discrepancyCount'] === 0)
            <x-filament::section>
                <div class="flex items-center gap-2 text-success-600 dark:text-success-400">
                    <x-filament::icon icon="heroicon-o-check-circle" class="h-6 w-6" />
                    <span class="font-medium">Όλα τα τοπικά παραστατικά συμφωνούν με το AADE.</span>
                </div>
            </x-filament::section>
        @endif

        @foreach ([
            ['key' => 'stateMismatch', 'title' => 'Ασυμφωνία κατάστασης', 'color' => 'warning', 'icon' => 'heroicon-o-exclamation-triangle'],
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

        {{-- Λείπουν από το AADE — the genuinely worrying bucket (all of them in
             production; native-only in sandbox). --}}
        @if (count($missingReal) > 0)
            <x-filament::section :collapsible="true">
                <x-slot name="heading">
                    <span class="flex items-center gap-2">
                        <x-filament::icon icon="heroicon-o-x-circle" class="h-5 w-5 text-danger-500" />
                        Λείπουν από το AADE
                        <x-filament::badge color="danger">{{ count($missingReal) }}</x-filament::badge>
                    </span>
                </x-slot>
                <x-slot name="description">Τα φιλοξενούμε ως υποβληθέντα, αλλά το AADE δεν τα επιστρέφει — χρειάζονται έλεγχο.</x-slot>

                @include('filament.pages.partials.reconciliation-table', [
                    'rows' => $missingReal,
                    'columns' => ['invcode', 'mark', 'issuedAt', 'counterpart', 'gross', 'localState', 'aadeState', 'problem', 'open'],
                ])
            </x-filament::section>
        @endif

        {{-- Εισαγμένα — production MARKs a SANDBOX can't see. Informational, collapsed.
             (Non-empty only in sandbox; in production these are real → above.) --}}
        @if (count($missingNoise) > 0)
            <x-filament::section :collapsible="true" :collapsed="true">
                <x-slot name="heading">
                    <span class="flex items-center gap-2">
                        <x-filament::icon icon="heroicon-o-archive-box" class="h-5 w-5 text-gray-400" />
                        Εισαγμένα (ΜΑΡΚ άλλου καναλιού)
                        <x-filament::badge color="gray">{{ count($missingNoise) }}</x-filament::badge>
                    </span>
                </x-slot>
                <x-slot name="description">Εισαγμένα από το προηγούμενο σύστημα με ΜΑΡΚ παραγωγής — δεν επιστρέφονται στο sandbox. Ενημερωτικό.</x-slot>

                @include('filament.pages.partials.reconciliation-table', [
                    'rows' => $missingNoise,
                    'columns' => ['invcode', 'mark', 'issuedAt', 'counterpart', 'gross', 'localState', 'aadeState', 'problem', 'open'],
                ])
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

        {{-- ============================================================
             Direction 2 — myDATA → ΕΜΑΣ ("αδέσποτα"): ό,τι έχει το myDATA
             για το ΑΦΜ μας χωρίς τοπική εγγραφή.
             ============================================================ --}}
        <div class="flex items-center gap-2 pt-4 text-sm font-semibold text-gray-700 dark:text-gray-200">
            <x-filament::icon icon="heroicon-o-cloud-arrow-down" class="h-5 w-5 text-warning-500" />
            Αδέσποτα από myDATA
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

        {{-- INCOME orphans: the actionable list — a sale already at myDATA
             with no local record. --}}
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

        {{-- SUPPLIER EXPENSES: not sales — belong to the Έξοδα console. --}}
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

        {{-- OTHER self-declared entries: payroll, fixed assets, ΕΦΚΑ. Info-only. --}}
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
    @endif
</x-filament-panels::page>
