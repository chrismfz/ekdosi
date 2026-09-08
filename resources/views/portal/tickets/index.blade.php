@php
    // Filament status colour token → Flux badge colour.
    $fluxColor = ['warning' => 'amber', 'danger' => 'red', 'success' => 'green', 'info' => 'blue', 'gray' => 'zinc'];
@endphp
<x-portal-layout title="Τα αιτήματά μου">
    <div class="mb-6 flex flex-wrap items-start justify-between gap-3">
        <div>
            <flux:heading size="xl">Τα αιτήματά μου</flux:heading>
            <flux:text class="mt-2">Καλωσήρθες, {{ $user->name }}. Δες τα αιτήματα υποστήριξης ή άνοιξε νέο.</flux:text>
        </div>
        <flux:button size="sm" variant="primary" icon="plus" href="{{ route('portal.tickets.create') }}">Νέο αίτημα</flux:button>
    </div>

    @if ($tickets->isEmpty())
        <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
            <flux:text>Δεν έχεις ανοίξει κάποιο αίτημα ακόμη.</flux:text>
        </div>
    @else
        <div class="overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-700">
            <table class="w-full text-sm">
                <thead class="bg-zinc-50 text-left dark:bg-zinc-800/50">
                    <tr class="[&>th]:px-4 [&>th]:py-2 [&>th]:font-medium">
                        <th>Κωδικός</th>
                        <th>Θέμα</th>
                        <th>Τμήμα</th>
                        <th>Κατάσταση</th>
                        <th>Τελευταία απάντηση</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @foreach ($tickets as $ticket)
                        <tr class="[&>td]:px-4 [&>td]:py-2.5">
                            <td class="font-medium whitespace-nowrap">
                                <flux:link href="{{ route('portal.tickets.show', $ticket->id) }}">{{ $ticket->reference }}</flux:link>
                            </td>
                            <td>{{ \Illuminate\Support\Str::limit($ticket->subject, 60) }}</td>
                            <td class="whitespace-nowrap text-zinc-500">{{ $ticket->department?->name ?? '—' }}</td>
                            <td>
                                <flux:badge size="sm" color="{{ $fluxColor[$ticket->status->getColor()] ?? 'zinc' }}">{{ $ticket->status->getLabel() }}</flux:badge>
                            </td>
                            <td class="whitespace-nowrap text-zinc-500">{{ $ticket->last_reply_at?->diffForHumans() ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-portal-layout>
