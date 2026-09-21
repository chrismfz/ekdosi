@php use App\Support\Money; @endphp
<x-portal-layout title="{{ __('portal.common.my_statement') }}">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <flux:heading size="xl">{{ __('portal.common.my_statement') }}</flux:heading>
            <flux:text class="mt-2 mb-6">{{ __('portal.statement.welcome', ['name' => $user->name]) }}</flux:text>
        </div>
        <flux:button
            size="sm"
            variant="ghost"
            icon="document-text"
            href="{{ route('portal.home') }}"
        >{{ __('portal.common.my_documents') }}</flux:button>
    </div>

    @forelse ($statements as $st)
        <div class="mb-8">
            <div class="mb-3 flex flex-wrap items-baseline gap-x-2 gap-y-1">
                <flux:heading size="lg">{{ $st['customer'] }}</flux:heading>
                @if ($st['afm'])
                    <flux:text class="text-sm">{{ __('portal.common.afm') }} {{ $st['afm'] }}</flux:text>
                @endif
                <flux:badge size="sm" color="zinc">{{ $st['company'] }}</flux:badge>
                @if ($st['role'] === 'reseller')
                    <flux:badge size="sm" color="amber">{{ __('portal.common.via_me') }}</flux:badge>
                @endif
            </div>

            {{-- Balance card --}}
            <div class="mb-4 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                @if ($st['owed'] > 0)
                    <flux:text class="text-sm text-zinc-500">{{ __('portal.common.owed_balance') }}</flux:text>
                    <div class="mt-1 text-2xl font-semibold text-red-600 dark:text-red-400">{{ Money::eur($st['owed']) }}</div>
                    @if ($st['oldest_unpaid_days'] !== null)
                        <flux:text class="mt-1 text-sm text-zinc-500">{{ __('portal.statement.oldest_unpaid', ['days' => $st['oldest_unpaid_days']]) }}</flux:text>
                    @endif
                @elseif ($st['credit'] > 0)
                    <flux:text class="text-sm text-zinc-500">{{ __('portal.statement.credit_balance') }}</flux:text>
                    <div class="mt-1 text-2xl font-semibold text-green-600 dark:text-green-400">{{ Money::eur($st['credit']) }}</div>
                @elseif (count($st['rows']) === 0)
                    <flux:text class="text-sm text-zinc-500">{{ __('portal.common.balance') }}</flux:text>
                    <div class="mt-1 text-2xl font-semibold text-zinc-500 dark:text-zinc-400">{{ __('portal.statement.no_movement') }}</div>
                @else
                    <flux:text class="text-sm text-zinc-500">{{ __('portal.common.balance') }}</flux:text>
                    <div class="mt-1 text-2xl font-semibold text-zinc-700 dark:text-zinc-200">{{ __('portal.statement.settled') }}</div>
                @endif

                <div class="mt-3">
                    <flux:button size="sm" variant="primary" icon="credit-card"
                        href="{{ route('portal.payment.create', $st['customer_id']) }}">{{ __('portal.statement.pay') }}</flux:button>
                </div>
            </div>

            @if (count($st['rows']) === 0)
                <flux:text class="text-sm text-zinc-500">{{ __('portal.statement.no_activity_yet') }}</flux:text>
            @else
                <div class="overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-700">
                    <table class="w-full text-sm">
                        <thead class="bg-zinc-50 text-left dark:bg-zinc-800/50">
                            <tr class="[&>th]:px-4 [&>th]:py-2 [&>th]:font-medium">
                                <th>{{ __('portal.common.date') }}</th>
                                <th>{{ __('portal.statement.movement') }}</th>
                                <th class="text-right">{{ __('portal.common.charge') }}</th>
                                <th class="text-right">{{ __('portal.common.credit') }}</th>
                                <th class="text-right">{{ __('portal.common.balance') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            @foreach ($st['rows'] as $row)
                                <tr class="[&>td]:px-4 [&>td]:py-2.5">
                                    <td class="whitespace-nowrap">{{ $row['date'] }}</td>
                                    <td>
                                        @switch($row['kind'])
                                            @case('payment')
                                                <flux:badge size="sm" color="green">{{ __('portal.common.payment') }}</flux:badge>
                                                @break
                                            @case('credit')
                                                <flux:badge size="sm" color="blue">{{ __('portal.statement.badge_credit') }}</flux:badge>
                                                @break
                                            @case('refund')
                                                <flux:badge size="sm" color="amber">{{ __('portal.statement.badge_refund') }}</flux:badge>
                                                @break
                                            @case('proforma')
                                                <flux:badge size="sm" color="amber">{{ __('portal.payment.proforma_badge') }}</flux:badge>
                                                @break

                                            @default
                                                <flux:badge size="sm" color="zinc">{{ __('portal.common.document') }}</flux:badge>
                                        @endswitch
                                        @if (! empty($row['invoice_id']))
                                            <flux:link class="ml-1" href="{{ route('portal.document.show', $row['invoice_id']) }}">{{ $row['label'] }}</flux:link>
                                        @else
                                            <span class="ml-1">{{ $row['label'] }}</span>
                                        @endif
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
                    {{ __('portal.statement.balance_note') }}
                </flux:text>
            @endif

            <flux:text class="mt-3 text-sm">
                <flux:link href="{{ route('portal.home') }}">{{ __('portal.statement.see_my_documents') }}</flux:link>
            </flux:text>
        </div>
    @empty
        <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
            <flux:text>{{ __('portal.statement.empty') }}</flux:text>
        </div>
    @endforelse
</x-portal-layout>
