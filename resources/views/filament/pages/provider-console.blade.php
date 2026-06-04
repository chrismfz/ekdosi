<x-filament-panels::page>
    <x-filament::section heading="Ετοιμότητα παρόχου">
        <x-slot name="description">
            Έλεγχος ρυθμίσεων (χωρίς δικτυακή κλήση). «✗» = δεν θα εκδοθεί σωστά·
            «•» = σε αναμονή· «✓» = έτοιμο. Δεν εμφανίζονται διαπιστευτήρια.
        </x-slot>

        <div class="space-y-2">
            @foreach ($checks as $check)
                @php
                    [$icon, $color] = match ($check['status']) {
                        'ok' => ['✓', 'success'],
                        'warn' => ['•', 'warning'],
                        default => ['✗', 'danger'],
                    };
                @endphp
                <div class="flex items-start gap-3">
                    <x-filament::badge :color="$color">{{ $icon }}</x-filament::badge>
                    <div class="text-sm">
                        <span class="font-semibold">{{ $check['label'] }}</span>
                        <span class="text-gray-500 dark:text-gray-400">— {{ $check['detail'] }}</span>
                    </div>
                </div>
            @endforeach
        </div>
    </x-filament::section>

    <x-filament::section heading="Πρόσφατες υποβολές μέσω παρόχου">
        @if ($marks->isEmpty())
            <p class="text-sm text-gray-500 dark:text-gray-400">Καμία υποβολή μέσω παρόχου ακόμη.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-left text-gray-500 dark:text-gray-400">
                        <tr>
                            <th class="py-1 pr-4">Ενέργεια</th>
                            <th class="py-1 pr-4">ΜΑΡΚ</th>
                            <th class="py-1 pr-4">Πάροχος</th>
                            <th class="py-1 pr-4">Auth code</th>
                            <th class="py-1 pr-4">Παράδοση</th>
                            <th class="py-1 pr-4">Ημ/νία</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($marks as $mark)
                            <tr class="border-t border-gray-100 dark:border-white/10">
                                <td class="py-1 pr-4 whitespace-nowrap">{{ $mark->mydata_action }}</td>
                                <td class="py-1 pr-4 whitespace-nowrap font-mono">{{ $mark->mark ?: '—' }}</td>
                                <td class="py-1 pr-4 whitespace-nowrap">{{ $mark->provider_key ?: '—' }}</td>
                                <td class="py-1 pr-4 whitespace-nowrap font-mono">{{ \Illuminate\Support\Str::limit($mark->authentication_code, 12) ?: '—' }}</td>
                                <td class="py-1 pr-4 whitespace-nowrap">{{ $mark->delivery_state ?: '—' }}</td>
                                <td class="py-1 pr-4 whitespace-nowrap">{{ $mark->mark_date?->format('d/m/Y') ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
