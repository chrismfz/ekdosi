<x-filament-panels::page>
    @php($s = $this->stats())
    @php($obsolete = $this->obsolete())

    <div class="grid grid-cols-1 gap-3 md:grid-cols-3 mb-4">
        <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
            <div class="text-xs fi-color-gray">Φορτωμένο έτος</div>
            <div class="text-lg font-bold">{{ $s['year'] ?? '—' }}</div>
            <div class="text-xs fi-color-gray">{{ number_format($s['count'], 0, ',', '.') }} κωδικοί 8 ψηφίων</div>
        </div>
        <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
            <div class="text-xs fi-color-gray">Είδη με καταργημένο κωδικό</div>
            <div class="text-lg font-bold {{ $obsolete->isNotEmpty() ? 'text-danger-600 dark:text-danger-400' : '' }}">{{ $obsolete->count() }}</div>
            <div class="text-xs fi-color-gray">δεν υπάρχουν στη ΣΟ {{ $s['year'] ?? '' }}</div>
        </div>
        <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
            <div class="text-xs fi-color-gray">Διαθέσιμα έτη</div>
            <div class="text-sm">@forelse ($s['years'] as $y => $n){{ $y }} ({{ number_format($n, 0, ',', '.') }})@if (! $loop->last) · @endif @empty — @endforelse</div>
        </div>
    </div>

    <x-filament::section heading="Είδη με κωδικό που δεν υπάρχει στη ΣΟ {{ $s['year'] ?? '' }}" class="mb-4">
        @if ($obsolete->isEmpty())
            <div class="fi-color-gray text-sm">Κανένα — όλοι οι κωδικοί TARIC των ειδών υπάρχουν στον τρέχοντα κατάλογο.</div>
        @else
            <table class="min-w-full text-sm">
                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                    @foreach ($obsolete as $p)
                        <tr>
                            <td class="py-1">
                                <a class="underline" href="{{ \App\Filament\Resources\Products\ProductResource::getUrl('edit', ['record' => $p]) }}">{{ $p->description_short }}</a>
                            </td>
                            <td class="text-right whitespace-nowrap">{{ $p->taric_code }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <div class="text-xs fi-color-gray mt-2">Άνοιξε το είδος και διάλεξε τον νέο κωδικό (η ΣΟ αλλάζει κάθε 1η Ιανουαρίου).</div>
        @endif
    </x-filament::section>

    <x-filament::section heading="Πηγή">
        <div class="text-sm">
            Συνδυασμένη Ονοματολογία (ΣΟ) — Εκτελεστικός Κανονισμός (ΕΕ) 2025/1926 για το 2026· επίσημη ανοιχτή διανομή της
            Υπηρεσίας Εκδόσεων της ΕΕ (data.europa.eu, «combined-nomenclature-{έτος}»), © Ευρωπαϊκή Ένωση, επαναχρησιμοποίηση
            κατά την ανακοίνωση της Επιτροπής (CC BY 4.0). Στο myDATA ο κωδικός στέλνεται 10ψήφιος (8 ψηφία ΣΟ + «00»), μόνο σε
            Τιμολόγια–Δελτία Αποστολής και Δελτία Διακίνησης.
        </div>
    </x-filament::section>
</x-filament-panels::page>
