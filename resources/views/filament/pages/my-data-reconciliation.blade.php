<x-filament-panels::page>
    {{-- Make the scope explicit: this is a LOCAL check (no AADE call), so it can
         legitimately say «καμία ασυμφωνία» while the live Κονσόλα myDATA reports
         differences — they measure different things. Without this note the two
         screens look contradictory. --}}
    <x-filament::section>
        <div class="flex items-start gap-2 text-sm text-gray-600 dark:text-gray-300">
            <x-filament::icon icon="heroicon-o-information-circle" class="mt-0.5 h-5 w-5 shrink-0 text-gray-400" />
            <span>
                <strong>Εσωτερικός έλεγχος — δεν ρωτά το AADE.</strong>
                Συγκρίνει μόνο τις δύο δικές μας στήλες (τοπική κατάσταση ↔ καταγεγραμμένη κατάσταση myDATA)
                και δείχνει όσα παραστατικά αντιφάσκουν. Για <em>ζωντανή</em> διασταύρωση με ό,τι τηρεί
                πραγματικά η ΑΑΔΕ, δες την <strong>Κονσόλα myDATA</strong>.
            </span>
        </div>
    </x-filament::section>

    {{ $this->table }}
</x-filament-panels::page>
