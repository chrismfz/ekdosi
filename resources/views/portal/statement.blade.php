@php use App\Support\Money; @endphp
<x-portal-layout title="Η καρτέλα μου">
    <flux:heading size="xl">Η καρτέλα μου</flux:heading>
    <flux:text class="mt-2 mb-6">Καλωσήρθες, {{ $user->name }}. Εδώ βλέπεις το υπόλοιπο και τις κινήσεις σου.</flux:text>

    @forelse ($statements as $st)
        <div class="mb-8">
            <div class="mb-3 flex flex-wrap items-baseline gap-x-2 gap-y-1">
                <flux:heading size="lg">{{ $st['customer'] }}</flux:heading>
                @if ($st['afm'])
                    <flux:text class="text-sm">ΑΦΜ {{ $st['afm'] }}</flux:text>
                @endif
                <flux:badge size="sm" color="zinc">{{ $st['company'] }}</flux:badge>
                @if ($st['role'] === 'reseller')
                    <flux:badge size="sm" color="amber">μέσω εμού</flux:badge>
                @endif
            </div>

            {{-- Balance card --}}
            <div class="mb-4 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                @if ($st['owed'] > 0)
                    <flux:text class="text-sm text-zinc-500">Οφειλόμενο υπόλοιπο</flux:text>
                    <div class="mt-1 text-2xl font-semibold text-red-600 dark:text-red-400">{{ Money::eur($st['owed']) }}</div>
                    @if ($st['oldest_unpaid_days'] !== null)
                        <flux:text class="mt-1 text-sm text-zinc-500">Παλαιότερο ανεξόφλητο: {{ $st['oldest_unpaid_days'] }} ημέρες</flux:text>
                    @endif
                @elseif ($st['credit'] > 0)
                    <flux:text class="text-sm text-zinc-500">Πιστωτικό υπόλοιπο (υπέρ σου)</flux:text>
                    <div class="mt-1 text-2xl font-semibold text-green-600 dark:text-green-400">{{ Money::eur($st['credit']) }}</div>
                @elseif (count($st['rows']) === 0)
                    <flux:text class="text-sm text-zinc-500">Υπόλοιπο</flux:text>
                    <div class="mt-1 text-2xl font-semibold text-zinc-500 dark:text-zinc-400">Χωρίς κίνηση</div>
                @else
                    <flux:text class="text-sm text-zinc-500">Υπόλοιπο</flux:text>
                    <div class="mt-1 text-2xl font-semibold text-zinc-700 dark:text-zinc-200">Εξοφλημένο</div>
                @endif

                <div class="mt-3">
                    <flux:button size="sm" variant="primary" icon="credit-card"
                        href="{{ route('portal.payment.create', $st['company_id']) }}">Πλήρωσε</flux:button>
                </div>
            </div>

            @if (count($st['rows']) === 0)
                <flux:text class="text-sm text-zinc-500">Καμία κίνηση ακόμη.</flux:text>
            @else
                <div class="overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-700">
                    <table class="w-full text-sm">
                        <thead class="bg-zinc-50 text-left dark:bg-zinc-800/50">
                            <tr class="[&>th]:px-4 [&>th]:py-2 [&>th]:font-medium">
                                <th>Ημ/νία</th>
                                <th>Κίνηση</th>
                                <th class="text-right">Χρέωση</th>
                                <th class="text-right">Πίστωση</th>
                                <th class="text-right">Υπόλοιπο</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            @foreach ($st['rows'] as $row)
                                <tr class="[&>td]:px-4 [&>td]:py-2.5">
                                    <td class="whitespace-nowrap">{{ $row['date'] }}</td>
                                    <td>
                                        @switch($row['kind'])
                                            @case('payment')
                                                <flux:badge size="sm" color="green">Πληρωμή</flux:badge>
                                                @break
                                            @case('credit')
                                                <flux:badge size="sm" color="blue">Πιστωτικό</flux:badge>
                                                @break
                                            @case('refund')
                                                <flux:badge size="sm" color="amber">Επιστροφή</flux:badge>
                                                @break
                                            @default
                                                <flux:badge size="sm" color="zinc">Παραστατικό</flux:badge>
                                        @endswitch
                                        <span class="ml-1">{{ $row['label'] }}</span>
                                    </td>
                                    <td class="text-right whitespace-nowrap">{{ $row['debit'] > 0 ? Money::eur($row['debit']) : '—' }}</td>
                                    <td class="text-right whitespace-nowrap">{{ $row['credit'] > 0 ? Money::eur($row['credit']) : '—' }}</td>
                                    <td class="text-right font-medium whitespace-nowrap">{{ Money::eur($row['running_balance']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <flux:text class="mt-2 text-xs text-zinc-500">
                    Το υπόλοιπο αφορά τα επί πιστώσει παραστατικά· οι τοις μετρητοίς πωλήσεις εξοφλούνται κατά την έκδοση.
                </flux:text>
            @endif

            <flux:text class="mt-3 text-sm">
                <flux:link href="{{ route('portal.home') }}">Δες τα παραστατικά μου</flux:link>
            </flux:text>
        </div>
    @empty
        <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
            <flux:text>Δεν υπάρχει καρτέλα διαθέσιμη για τον λογαριασμό σου ακόμη.</flux:text>
        </div>
    @endforelse
</x-portal-layout>
