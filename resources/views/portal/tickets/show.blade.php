@php
    use App\Enums\TicketStatus;
    $fluxColor = ['warning' => 'amber', 'danger' => 'red', 'success' => 'green', 'info' => 'blue', 'gray' => 'zinc'];
@endphp
<x-portal-layout title="Αίτημα {{ $ticket->reference }}">
    <div class="mb-4">
        <flux:link href="{{ route('portal.tickets') }}" class="text-sm">← Τα αιτήματά μου</flux:link>
    </div>

    <flux:heading size="xl">{{ $ticket->subject }}</flux:heading>
    <div class="mt-2 mb-6 flex flex-wrap items-center gap-2">
        <flux:badge size="sm" color="zinc">{{ $ticket->reference }}</flux:badge>
        <flux:badge size="sm" color="{{ $fluxColor[$ticket->status->getColor()] ?? 'zinc' }}">{{ $ticket->status->getLabel() }}</flux:badge>
        @if ($ticket->department)
            <flux:text class="text-sm text-zinc-500">{{ $ticket->department->name }}</flux:text>
        @endif
    </div>

    {{-- Thread — public messages only (internal notes never reach the portal). --}}
    <div class="mb-6 flex flex-col gap-3">
        @foreach ($ticket->publicMessages as $msg)
            <div class="rounded-xl border p-4 {{ $msg->isFromCustomer()
                ? 'border-blue-200 bg-blue-50 dark:border-blue-900 dark:bg-blue-950/40'
                : 'border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800' }}">
                <div class="mb-1 flex items-center justify-between gap-2">
                    <flux:badge size="sm" color="{{ $msg->isFromCustomer() ? 'blue' : 'zinc' }}">{{ $msg->isFromCustomer() ? 'Εσείς' : 'Υποστήριξη' }}</flux:badge>
                    <flux:text class="text-xs text-zinc-500">{{ $msg->created_at->diffForHumans() }}</flux:text>
                </div>
                <div class="text-sm whitespace-pre-line">{{ $msg->body }}</div>
            </div>
        @endforeach
    </div>

    {{-- Feedback on close — only on a closed ticket of a feedback-enabled department. --}}
    @if ($ticket->canBeRated())
        <div class="mb-6 max-w-xl rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
            <flux:heading size="sm">Πώς σας φάνηκε η εξυπηρέτηση;</flux:heading>
            @if ($ticket->isRated())
                <flux:text class="mt-1 text-sm text-zinc-500">Η αξιολόγησή σας: {{ $ticket->rating }}/5 — μπορείτε να την αλλάξετε.</flux:text>
            @endif
            <form method="POST" action="{{ route('portal.tickets.rate', $ticket->id) }}" class="mt-3 flex flex-col gap-3">
                @csrf
                <div class="flex flex-wrap gap-2">
                    @for ($i = 1; $i <= 5; $i++)
                        <label class="cursor-pointer rounded-lg border px-3 py-2 text-sm has-[:checked]:border-blue-500 has-[:checked]:bg-blue-50 dark:has-[:checked]:bg-blue-950/40">
                            <input type="radio" name="rating" value="{{ $i }}" class="sr-only" required @checked((int) old('rating', $ticket->rating) === $i)>
                            {{ $i }} ★
                        </label>
                    @endfor
                </div>
                @error('rating') <flux:text class="text-sm text-red-600">{{ $message }}</flux:text> @enderror
                <flux:textarea name="rating_comment" label="Σχόλιο (προαιρετικά)" rows="2">{{ old('rating_comment', $ticket->rating_comment) }}</flux:textarea>
                @error('rating_comment') <flux:text class="text-sm text-red-600">{{ $message }}</flux:text> @enderror
                <div><flux:button type="submit" variant="primary">Υποβολή αξιολόγησης</flux:button></div>
            </form>
        </div>
    @endif

    {{-- Reply --}}
    @if ($ticket->status === TicketStatus::Closed)
        <flux:text class="mb-2 text-sm text-zinc-500">Το αίτημα είναι κλειστό — μια νέα απάντηση θα το ανοίξει ξανά.</flux:text>
    @endif
    <form method="POST" action="{{ route('portal.tickets.reply', $ticket->id) }}" class="flex max-w-xl flex-col gap-3">
        @csrf
        <flux:textarea name="body" label="Η απάντησή σας" rows="4" required>{{ old('body') }}</flux:textarea>
        @error('body') <flux:text class="text-sm text-red-600">{{ $message }}</flux:text> @enderror
        <div><flux:button type="submit" variant="primary">Αποστολή απάντησης</flux:button></div>
    </form>
</x-portal-layout>
