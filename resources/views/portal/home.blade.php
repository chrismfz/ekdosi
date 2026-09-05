<x-portal-layout title="Τα παραστατικά μου">
    <flux:heading size="xl">Τα παραστατικά μου</flux:heading>
    <flux:text class="mt-2 mb-6">Καλωσήρθες, {{ $user->name }}. Εδώ βλέπεις τα εκδοθέντα παραστατικά σου.</flux:text>

    @forelse ($groups as $group)
        <div class="mb-8">
            <div class="mb-3 flex flex-wrap items-baseline gap-x-2 gap-y-1">
                <flux:heading size="lg">{{ $group['customer'] }}</flux:heading>
                @if ($group['afm'])
                    <flux:text class="text-sm">ΑΦΜ {{ $group['afm'] }}</flux:text>
                @endif
                <flux:badge size="sm" color="zinc">{{ $group['company'] }}</flux:badge>
                @if ($group['role'] === 'reseller')
                    <flux:badge size="sm" color="amber">μέσω εμού</flux:badge>
                @endif
            </div>

            @if (count($group['documents']) === 0)
                <flux:text class="text-sm text-zinc-500">Δεν υπάρχουν παραστατικά ακόμη.</flux:text>
            @else
                <div class="overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-700">
                    <table class="w-full text-sm">
                        <thead class="bg-zinc-50 text-left dark:bg-zinc-800/50">
                            <tr class="[&>th]:px-4 [&>th]:py-2 [&>th]:font-medium">
                                <th>Ημ/νία</th>
                                <th>Παραστατικό</th>
                                <th>Τύπος</th>
                                <th>Κατάσταση</th>
                                <th>ΜΑΡΚ</th>
                                <th class="text-right">Λήψη</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            @foreach ($group['documents'] as $doc)
                                <tr class="[&>td]:px-4 [&>td]:py-2.5">
                                    <td class="whitespace-nowrap">{{ $doc['issued_at'] ?? '—' }}</td>
                                    <td class="font-medium">{{ $doc['invcode'] ?? '—' }}</td>
                                    <td>{{ $doc['type'] ?? '—' }}</td>
                                    <td>
                                        @if ($doc['mydata_state'] === 'VALID')
                                            <flux:badge size="sm" color="green">Στο myDATA</flux:badge>
                                        @else
                                            <flux:badge size="sm" color="blue">Εκδόθηκε</flux:badge>
                                        @endif
                                    </td>
                                    <td class="font-mono text-xs text-zinc-500">{{ $doc['mydata_mark'] ?? '—' }}</td>
                                    <td class="text-right whitespace-nowrap">
                                        <flux:button
                                            size="xs"
                                            variant="ghost"
                                            icon="document-arrow-down"
                                            href="{{ route('portal.document.pdf', $doc['id']) }}"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                        >PDF</flux:button>
                                        @if ($doc['verify_url'])
                                            <flux:button
                                                size="xs"
                                                variant="ghost"
                                                icon="arrow-top-right-on-square"
                                                href="{{ $doc['verify_url'] }}"
                                                target="_blank"
                                                rel="noopener noreferrer"
                                            >Επαλήθευση</flux:button>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if ($group['truncated'])
                    <flux:text class="mt-2 text-xs text-zinc-500">Εμφανίζονται τα πιο πρόσφατα {{ count($group['documents']) }} παραστατικά. Για παλαιότερα, επικοινώνησε μαζί μας.</flux:text>
                @endif
            @endif
        </div>
    @empty
        <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
            <flux:text>Δεν υπάρχουν παραστατικά διαθέσιμα για τον λογαριασμό σου ακόμη.</flux:text>
        </div>
    @endforelse
</x-portal-layout>
