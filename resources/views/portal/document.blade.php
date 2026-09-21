@php
    use App\Support\Money;

    $isCredit = $invoice->isCreditNote();
    $isProforma = $invoice->isOffered();
    $net = (float) $invoice->net_total;
    $gross = (float) $invoice->gross_total;
    $vat = round($gross - $net, 2);
    $withhold = (float) ($invoice->withhold_amount ?? 0);
    $payable = (float) ($invoice->payable_total ?? $gross);
    $discount = (float) ($invoice->header_discount_percent ?? 0);
    $hasLinks = $original !== null || $creditNotes->isNotEmpty() || $invoice->payments->isNotEmpty();
@endphp

<x-portal-layout title="{{ ($invoice->invcode ?? __('portal.document.title')) }}">
    {{-- Back --}}
    <flux:text class="mb-4">
        <flux:link href="{{ route('portal.home') }}">&larr; {{ __('portal.document.back_to_documents') }}</flux:link>
    </flux:text>

    {{-- Header --}}
    <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
        <div>
            <flux:heading size="xl">{{ $invoice->invcode ?? '—' }}</flux:heading>
            <flux:text class="mt-1">
                {{ $invoice->invoiceType?->name ?? __('portal.common.document') }}
                @if ($invoice->issued_at)
                    · {{ $invoice->issued_at->format('d/m/Y') }}
                @endif
            </flux:text>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            @if ($isProforma)
                <flux:badge size="sm" color="amber">{{ __('portal.payment.proforma_badge') }}</flux:badge>
            @elseif ($invoice->mydata_state === 'VALID')
                <flux:badge size="sm" color="green">{{ __('portal.common.on_mydata') }}</flux:badge>
            @else
                <flux:badge size="sm" color="blue">{{ __('portal.common.issued') }}</flux:badge>
            @endif

            <flux:button size="xs" variant="ghost" icon="document-arrow-down"
                href="{{ route('portal.document.pdf', $invoice->id) }}"
                target="_blank" rel="noopener noreferrer">{{ __('portal.common.pdf') }}</flux:button>
            @if ($invoice->mydata_url)
                <flux:button size="xs" variant="ghost" icon="arrow-top-right-on-square"
                    href="{{ $invoice->mydata_url }}"
                    target="_blank" rel="noopener noreferrer">{{ __('portal.common.verify') }}</flux:button>
            @endif
        </div>
    </div>

    {{-- Parties --}}
    <div class="mb-4 grid gap-3 sm:grid-cols-2">
        <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
            <flux:text class="text-xs text-zinc-500">{{ __('portal.document.issuer') }}</flux:text>
            <div class="mt-1 font-medium">{{ $invoice->company?->name ?? '—' }}</div>
            @if ($invoice->company?->afm)
                <flux:text class="text-sm text-zinc-500">{{ __('portal.common.afm') }} {{ $invoice->company->afm }}</flux:text>
            @endif
        </div>
        <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
            <flux:text class="text-xs text-zinc-500">{{ __('portal.document.recipient') }}</flux:text>
            <div class="mt-1 font-medium">{{ $invoice->customer?->name ?? '—' }}</div>
            @if ($invoice->customer?->afm)
                <flux:text class="text-sm text-zinc-500">{{ __('portal.common.afm') }} {{ $invoice->customer->afm }}</flux:text>
            @endif
        </div>
    </div>

    {{-- MARK --}}
    @if ($invoice->mydata_mark)
        <div class="mb-4 rounded-xl border border-zinc-200 px-4 py-3 dark:border-zinc-700">
            <flux:text class="text-xs text-zinc-500">{{ __('portal.common.mark') }}</flux:text>
            <div class="font-mono text-sm break-all">{{ $invoice->mydata_mark }}</div>
        </div>
    @endif

    {{-- Lines --}}
    @if ($invoice->lines->isNotEmpty())
        <div class="mb-4 overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-700">
            <table class="w-full text-sm">
                <thead class="bg-zinc-50 text-left dark:bg-zinc-800/50">
                    <tr class="[&>th]:px-4 [&>th]:py-2 [&>th]:font-medium">
                        <th>{{ __('portal.document.line_description') }}</th>
                        <th class="text-right">{{ __('portal.document.qty') }}</th>
                        <th class="text-right">{{ __('portal.document.unit_price') }}</th>
                        <th class="text-right">{{ __('portal.document.vat') }}</th>
                        <th class="text-right">{{ __('portal.document.net') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @foreach ($invoice->lines as $line)
                        <tr class="[&>td]:px-4 [&>td]:py-2.5">
                            <td>{{ $line->product_descr ?? '—' }}</td>
                            <td class="text-right whitespace-nowrap">
                                {{ rtrim(rtrim(number_format((float) $line->qty, 3), '0'), '.') }}{{ $line->metric_unit ? ' '.$line->metric_unit : '' }}
                            </td>
                            <td class="text-right whitespace-nowrap">{{ Money::eur((float) $line->price_per_item) }}</td>
                            <td class="text-right whitespace-nowrap">{{ rtrim(rtrim(number_format((float) $line->vat_percent, 2), '0'), '.') }}%</td>
                            <td class="text-right whitespace-nowrap">{{ Money::eur((float) $line->net_price) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    {{-- Totals --}}
    <div class="mb-4 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
        <dl class="space-y-1.5 text-sm">
            @if ($discount > 0)
                <div class="flex justify-between">
                    <dt class="text-zinc-500">{{ __('portal.document.discount') }}</dt>
                    <dd>{{ rtrim(rtrim(number_format($discount, 2), '0'), '.') }}%</dd>
                </div>
            @endif
            <div class="flex justify-between">
                <dt class="text-zinc-500">{{ __('portal.document.net_total') }}</dt>
                <dd class="whitespace-nowrap">{{ Money::eur($net) }}</dd>
            </div>
            <div class="flex justify-between">
                <dt class="text-zinc-500">{{ __('portal.document.vat_total') }}</dt>
                <dd class="whitespace-nowrap">{{ Money::eur($vat) }}</dd>
            </div>
            @if ($withhold > 0)
                <div class="flex justify-between">
                    <dt class="text-zinc-500">{{ __('portal.document.withholding') }}</dt>
                    <dd class="whitespace-nowrap">−{{ Money::eur($withhold) }}</dd>
                </div>
            @endif
            <div class="flex justify-between border-t border-zinc-200 pt-1.5 text-base font-semibold dark:border-zinc-700">
                <dt>{{ __('portal.document.gross_total') }}</dt>
                <dd class="whitespace-nowrap">{{ Money::eur($gross) }}</dd>
            </div>
            @if (round($payable, 2) !== round($gross, 2))
                <div class="flex justify-between">
                    <dt class="text-zinc-500">{{ __('portal.document.payable') }}</dt>
                    <dd class="whitespace-nowrap">{{ Money::eur($payable) }}</dd>
                </div>
            @endif
        </dl>

        {{-- Payment status (not for credit notes — their reduction shows on the original) --}}
        @unless ($isCredit)
            <div class="mt-3 flex flex-wrap items-center justify-between gap-2 border-t border-zinc-200 pt-3 dark:border-zinc-700">
                <div class="flex items-center gap-2">
                    <flux:text class="text-sm text-zinc-500">{{ __('portal.document.payment_status') }}</flux:text>
                    <flux:badge size="sm" :color="match ($balance->status->value) {
                        'paid' => 'green', 'partial' => 'amber', 'credited' => 'blue', 'overpaid' => 'amber', default => 'zinc',
                    }">{{ __('portal.document.status_'.$balance->status->value) }}</flux:badge>
                </div>
                @if ($balance->balance > 0.005)
                    <flux:text class="text-sm">{{ __('portal.common.balance') }}: <span class="font-semibold text-red-600 dark:text-red-400">{{ Money::eur($balance->balance) }}</span></flux:text>
                @endif
            </div>
        @endunless
    </div>

    {{-- Linked documents --}}
    <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
        <flux:heading size="lg">{{ __('portal.document.linked_title') }}</flux:heading>

        @unless ($hasLinks)
            <flux:text class="mt-2 text-sm text-zinc-500">{{ __('portal.document.no_links') }}</flux:text>
        @endunless

        @if ($original !== null)
            <div class="mt-3">
                <flux:text class="text-xs text-zinc-500">{{ __('portal.document.credit_of') }}</flux:text>
                <div class="mt-1">
                    <flux:link href="{{ route('portal.document.show', $original->id) }}">{{ $original->invcode ?? '—' }}</flux:link>
                    @if ($original->invoiceType?->name)
                        <span class="text-zinc-500">· {{ $original->invoiceType->name }}</span>
                    @endif
                </div>
            </div>
        @endif

        @if ($creditNotes->isNotEmpty())
            <div class="mt-3">
                <flux:text class="text-xs text-zinc-500">{{ __('portal.document.credit_notes') }}</flux:text>
                <ul class="mt-1 space-y-1">
                    @foreach ($creditNotes as $cn)
                        <li class="text-sm">
                            <flux:link href="{{ route('portal.document.show', $cn->id) }}">{{ $cn->invcode ?? '—' }}</flux:link>
                            @if ($cn->issued_at)
                                <span class="text-zinc-500">· {{ $cn->issued_at->format('d/m/Y') }}</span>
                            @endif
                            <span class="whitespace-nowrap">· {{ Money::eur((float) $cn->gross_total) }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if ($invoice->payments->isNotEmpty())
            <div class="mt-3">
                <flux:text class="text-xs text-zinc-500">{{ __('portal.document.receipts') }}</flux:text>
                <ul class="mt-1 space-y-1">
                    @foreach ($invoice->payments as $p)
                        <li class="flex flex-wrap items-center gap-2 text-sm">
                            @if ($p->kind === 'refund')
                                <flux:badge size="sm" color="amber">{{ __('portal.statement.badge_refund') }}</flux:badge>
                            @else
                                <flux:badge size="sm" color="green">{{ __('portal.common.payment') }}</flux:badge>
                            @endif
                            <span>{{ $p->pay_date?->format('d/m/Y') }}</span>
                            <span class="font-medium whitespace-nowrap">{{ Money::eur((float) $p->amount) }}</span>
                            @if ($p->paymentMethod?->description)
                                <span class="text-zinc-500">· {{ __('portal.document.method') }}: {{ $p->paymentMethod->description }}</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    </div>

    @if ($isProforma)
        <flux:text class="mt-4 text-xs text-zinc-500">{{ __('portal.payment.proforma_note') }}</flux:text>
    @endif
</x-portal-layout>
