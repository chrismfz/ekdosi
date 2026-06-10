<x-filament-panels::page>
    <div class="text-sm text-gray-500 dark:text-gray-400">
        Εργαλεία συντήρησης για την τρέχουσα εταιρία. Κάθε κουμπί τρέχει την ίδια
        εντολή που τρέχει ο προγραμματιστής/scheduler, χωρίς terminal. Εκτελούνται
        μόνο ασφαλείς, επαναλήψιμες ενέργειες (δεν εκδίδουν/ακυρώνουν/διαγράφουν).
    </div>

    @if ($lastOutput !== null)
        <x-filament::section>
            <x-slot name="heading">
                Αποτέλεσμα: <code>{{ $lastCommand }}</code>
                @if ($lastStatus === 'ok')
                    <x-filament::badge color="success">OK</x-filament::badge>
                @elseif ($lastStatus === 'warn')
                    <x-filament::badge color="warning">Προειδοποιήσεις</x-filament::badge>
                @else
                    <x-filament::badge color="danger">Σφάλμα</x-filament::badge>
                @endif
            </x-slot>

            <pre class="overflow-x-auto whitespace-pre-wrap text-xs leading-relaxed">{{ $lastOutput }}</pre>
        </x-filament::section>
    @endif
</x-filament-panels::page>
