@php use App\Support\Money; @endphp
<x-portal-layout title="Οδηγίες πληρωμής">
    <div class="mx-auto max-w-md">
        <flux:heading size="xl">Οδηγίες πληρωμής</flux:heading>

        @if ($intent->status === 'settled')
            <div class="mt-4 rounded-xl border border-green-200 bg-green-50 p-4 dark:border-green-900 dark:bg-green-950">
                <flux:text class="text-green-700 dark:text-green-300">Η πληρωμή καταχωρίστηκε. Ευχαριστούμε!</flux:text>
            </div>
        @else
            <flux:text class="mt-2 mb-4">Ακολούθησε τις οδηγίες για να ολοκληρώσεις την πληρωμή. Θα καταχωριστεί μόλις επιβεβαιωθεί.</flux:text>

            <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                <div class="flex items-baseline justify-between">
                    <flux:text class="text-sm text-zinc-500">Ποσό</flux:text>
                    <div class="text-xl font-semibold">{{ Money::eur($intent->amount) }}</div>
                </div>
                <div class="mt-2 flex items-baseline justify-between">
                    <flux:text class="text-sm text-zinc-500">Κωδικός αναφοράς</flux:text>
                    <div class="font-mono">{{ $intent->reference }}</div>
                </div>
            </div>

            @if ($intent->instructions)
                <div class="mt-4 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                    <flux:text class="mb-1 text-sm font-medium">Στοιχεία πληρωμής</flux:text>
                    <div class="text-sm whitespace-pre-line">{{ $intent->instructions }}</div>
                </div>
                <flux:text class="mt-2 text-xs text-zinc-500">Στην αιτιολογία, ανάφερε τον κωδικό {{ $intent->reference }}.</flux:text>
            @endif
        @endif

        <flux:text class="mt-6 text-sm">
            <flux:link href="{{ route('portal.statement') }}">Επιστροφή στην καρτέλα</flux:link>
        </flux:text>
    </div>
</x-portal-layout>
