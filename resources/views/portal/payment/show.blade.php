@php use App\Support\Money; @endphp
<x-portal-layout title="{{ __('portal.payment.instructions_title') }}">
    <div class="mx-auto max-w-md">
        <flux:heading size="xl">{{ __('portal.payment.instructions_title') }}</flux:heading>

        @if ($intent->status === 'settled')
            <div class="mt-4 rounded-xl border border-green-200 bg-green-50 p-4 dark:border-green-900 dark:bg-green-950">
                <flux:text class="text-green-700 dark:text-green-300">{{ __('portal.payment.settled') }}</flux:text>
            </div>
        @else
            <flux:text class="mt-2 mb-4">{{ __('portal.payment.follow_instructions') }}</flux:text>

            <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                <div class="flex items-baseline justify-between">
                    <flux:text class="text-sm text-zinc-500">{{ __('portal.payment.amount') }}</flux:text>
                    <div class="text-xl font-semibold">{{ Money::eur($intent->amount) }}</div>
                </div>
                <div class="mt-2 flex items-baseline justify-between">
                    <flux:text class="text-sm text-zinc-500">{{ __('portal.payment.reference') }}</flux:text>
                    <div class="font-mono">{{ $intent->reference }}</div>
                </div>
            </div>

            @if ($intent->instructions)
                <div class="mt-4 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                    <flux:text class="mb-1 text-sm font-medium">{{ __('portal.payment.details') }}</flux:text>
                    <div class="text-sm whitespace-pre-line">{{ $intent->instructions }}</div>
                </div>
                <flux:text class="mt-2 text-xs text-zinc-500">{{ __('portal.payment.mention_reference', ['reference' => $intent->reference]) }}</flux:text>
            @endif
        @endif

        <flux:text class="mt-6 text-sm">
            <flux:link href="{{ route('portal.statement') }}">{{ __('portal.payment.back_to_statement') }}</flux:link>
        </flux:text>
    </div>
</x-portal-layout>
