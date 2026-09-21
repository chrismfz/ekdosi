<x-filament-panels::page>
    <div class="text-sm fi-color-gray">
        Εσωτερικές σημειώσεις για τον/την <span class="font-medium">{{ $this->record->name }}</span> —
        δεν εκτυπώνονται και δεν αποστέλλονται στην ΑΑΔΕ. Χρησιμοποίησε «Είδος» και ετικέτες για να τις οργανώσεις,
        και το πεδίο αναζήτησης για να βρεις γρήγορα IP, hostname ή κωδικό.
    </div>

    {{ $this->table }}
</x-filament-panels::page>
