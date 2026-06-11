<x-filament-panels::page>
    <div class="text-sm text-gray-500 dark:text-gray-400">
        Οι δικές σου ρυθμίσεις εταιρείας: εμφάνιση PDF, πρότυπα email, αυτόματη αποστολή
        και συχνότητα αντιγράφων. Τα ευαίσθητα (SMTP server, διαπιστευτήρια myDATA / GSIS /
        WHMCS, κρυπτογράφηση &amp; προορισμοί αντιγράφων) ρυθμίζονται από τον διαχειριστή
        συστήματος. Κάθε αλλαγή καταγράφεται στο «Ιστορικό».
    </div>

    <form wire:submit="save">
        {{ $this->form }}

        <div class="mt-6">
            <x-filament::button type="submit" icon="heroicon-o-check">
                Αποθήκευση
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
