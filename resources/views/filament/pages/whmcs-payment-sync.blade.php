<x-filament-panels::page>
    <x-filament::section>
        <div class="flex items-start gap-2 text-sm text-gray-600 dark:text-gray-300">
            <x-filament::icon icon="heroicon-o-information-circle" class="mt-0.5 h-5 w-5 shrink-0 text-gray-400" />
            <span>
                <strong>Ανοιχτές οφειλές που πληρώθηκαν στο WHMCS.</strong>
                Παραστατικά εκδοθέντα <em>επί πιστώσει</em> που το WHMCS δείχνει πλέον πληρωμένα.
                Το «Καταγραφή πληρωμής» επιβεβαιώνει <strong>ζωντανά</strong> στο WHMCS και κλείνει το
                υπόλοιπο στο ekdosi — <strong>καμία αλλαγή δεν γίνεται στο WHMCS του πελάτη</strong>.
                Η λίστα ανανεώνεται προγραμματισμένα· «Ανανέωση τώρα» για άμεσο έλεγχο.
            </span>
        </div>
    </x-filament::section>

    {{ $this->table }}
</x-filament-panels::page>
