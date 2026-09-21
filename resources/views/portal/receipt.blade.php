@php
    use App\Support\Money;
    /** @var \App\Models\Payment $payment */
    $channelLabel = __('portal.receipt.channel_'.$provenance['channel_key'])
        .($provenance['gateway_name'] ? ' · '.$provenance['gateway_name'] : '');
@endphp

<x-portal-layout title="{{ __('portal.receipt.kind_'.$titleKey) }}">
    {{-- Back --}}
    <flux:text class="mb-4">
        <flux:link href="{{ route('portal.statement') }}">&larr; {{ __('portal.receipt.back_to_statement') }}</flux:link>
    </flux:text>

    {{-- Header --}}
    <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
        <div>
            <flux:heading size="xl">{{ __('portal.receipt.kind_'.$titleKey) }}</flux:heading>
            <flux:text class="mt-1">
                @if ($provenance['reference'])
                    {{ $provenance['reference'] }} ·
                @endif
                {{ $payment->pay_date?->format('d/m/Y') }}
            </flux:text>
        </div>
        <div class="text-right">
            <flux:badge size="sm" :color="$kind === 'refund' ? 'amber' : 'green'">
                {{ $kind === 'refund' ? __('portal.statement.badge_refund') : __('portal.common.payment') }}
            </flux:badge>
            <div class="mt-1 text-2xl font-semibold">{{ Money::eur($total) }}</div>
        </div>
    </div>

    {{-- Provenance («από πού ήρθε») + details. For a group only shared values show. --}}
    <div class="mb-4 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
        <dl class="grid gap-3 sm:grid-cols-2">
            <div>
                <dt class="text-xs text-zinc-500">{{ __('portal.receipt.channel') }}</dt>
                <dd class="mt-0.5">{{ $channelLabel }}</dd>
            </div>
            @if ($provenance['method'])
                <div>
                    <dt class="text-xs text-zinc-500">{{ __('portal.receipt.method') }}</dt>
                    <dd class="mt-0.5">{{ $provenance['method'] }}</dd>
                </div>
            @endif
            @if ($provenance['bank'])
                <div>
                    <dt class="text-xs text-zinc-500">{{ __('portal.receipt.bank_account') }}</dt>
                    <dd class="mt-0.5">{{ $provenance['bank'] }}</dd>
                </div>
            @endif
            @if ($provenance['transaction_id'])
                <div>
                    <dt class="text-xs text-zinc-500">{{ __('portal.receipt.transaction_id') }}</dt>
                    <dd class="mt-0.5 font-mono text-sm break-all">{{ $provenance['transaction_id'] }}</dd>
                </div>
            @endif
            @if ($provenance['reference'])
                <div>
                    <dt class="text-xs text-zinc-500">{{ __('portal.receipt.reference') }}</dt>
                    <dd class="mt-0.5 font-mono text-sm break-all">{{ $provenance['reference'] }}</dd>
                </div>
            @endif
            @if ($provenance['notes'])
                <div class="sm:col-span-2">
                    <dt class="text-xs text-zinc-500">{{ __('portal.receipt.notes') }}</dt>
                    <dd class="mt-0.5 whitespace-pre-line">{{ $provenance['notes'] }}</dd>
                </div>
            @endif
        </dl>
    </div>

    {{-- Parties --}}
    <div class="mb-4 grid gap-3 sm:grid-cols-2">
        <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
            <flux:text class="text-xs text-zinc-500">{{ __('portal.receipt.received_by') }}</flux:text>
            <div class="mt-1 font-medium">{{ $payment->company?->name ?? '—' }}</div>
        </div>
        <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
            <flux:text class="text-xs text-zinc-500">{{ __('portal.receipt.from_customer') }}</flux:text>
            <div class="mt-1 font-medium">{{ $payment->customer?->name ?? '—' }}</div>
        </div>
    </div>

    {{-- Settled documents (which invoices this money hit) --}}
    @if (count($allocations) > 0)
        <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
            <flux:heading size="lg">{{ __('portal.receipt.settles') }}</flux:heading>
            <ul class="mt-2 space-y-1">
                @foreach ($allocations as $a)
                    <li class="flex flex-wrap items-center justify-between gap-2 text-sm">
                        <span>
                            @if ($a['on_account'])
                                {{ __('portal.receipt.on_account') }}
                            @elseif ($a['invoice_id'])
                                <flux:link href="{{ route('portal.document.show', $a['invoice_id']) }}">{{ $a['invcode'] ?? '—' }}</flux:link>
                                @if ($a['type'])
                                    <span class="text-zinc-500">· {{ $a['type'] }}</span>
                                @endif
                            @else
                                {{-- Settled a document this login can't open — no code disclosed. --}}
                                {{ __('portal.document.title') }}
                            @endif
                        </span>
                        <span class="font-medium whitespace-nowrap">{{ Money::eur($a['amount']) }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    <flux:text class="mt-4 text-xs text-zinc-500">{{ __('portal.receipt.informal_note') }}</flux:text>
</x-portal-layout>
