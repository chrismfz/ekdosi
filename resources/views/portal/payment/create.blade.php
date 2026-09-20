@php use App\Support\Money; @endphp
<x-portal-layout title="{{ __('portal.common.payment') }}">
    <div class="mx-auto max-w-md">
        <flux:heading size="xl">{{ __('portal.common.payment') }}</flux:heading>
        <flux:text class="mt-2 mb-6">{{ $customer->name }}</flux:text>

        @if ($methods->isEmpty())
            <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
                <flux:text>{{ __('portal.payment.no_method') }}</flux:text>
            </div>
        @else
            @php
                // Resolve the pre-selected invoice to an ACTUAL payable row — if the
                // old id no longer resolves (paid/cancelled since the first submit),
                // fall back to «whole balance» rather than pre-filling a zero amount.
                $selectedId = old('invoice_id', $preselectedInvoiceId);
                $selectedInvoice = $selectedId ? $openInvoices->firstWhere('id', (int) $selectedId) : null;
                $defaultAmount = $selectedInvoice ? (float) $selectedInvoice->open_balance : $owed;
            @endphp
            <form method="POST" action="{{ route('portal.payment.store', $customer->id) }}" class="flex flex-col gap-5">
                @csrf

                {{-- WHAT are you paying: the whole balance (FIFO, oldest-first) or ONE
                     specific invoice. Native <select> so it renders/works even without
                     the Vite/Flux build. Picking an invoice pre-fills the amount to that
                     invoice's balance (progressive enhancement; server caps it anyway). --}}
                @if ($openInvoices->isNotEmpty())
                    <div>
                        <label for="pay-target" class="mb-2 block font-medium">{{ __('portal.payment.what') }}</label>
                        <select id="pay-target" name="invoice_id"
                            class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 dark:border-zinc-600 dark:bg-zinc-800">
                            <option value="" data-amount="{{ number_format($owed, 2, '.', '') }}"
                                @selected(! $selectedInvoice)>{{ __('portal.payment.whole_balance', ['amount' => Money::eur($owed)]) }}</option>
                            @foreach ($openInvoices as $inv)
                                <option value="{{ $inv->id }}" data-amount="{{ number_format((float) $inv->open_balance, 2, '.', '') }}"
                                    @selected($selectedInvoice && $selectedInvoice->id === $inv->id)>{{ $inv->invcode }} — {{ Money::eur($inv->open_balance) }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif

                <flux:input
                    name="amount"
                    type="number"
                    step="0.01"
                    min="0.01"
                    label="{{ __('portal.payment.amount_field') }}"
                    value="{{ old('amount', number_format($defaultAmount, 2, '.', '')) }}"
                    required
                    autofocus
                />
                @if ($owed > 0)
                    <flux:text class="-mt-3 text-sm text-zinc-500">{{ __('portal.common.owed_balance') }}: {{ Money::eur($owed) }}</flux:text>
                @endif

                {{-- Native radios (not flux:radio.group variant="cards"): the Flux
                     card group renders a <ui-radio> web-component that shows NOTHING
                     until the Vite/Flux build is served, so on a box without
                     `npm run build` the customer saw an empty «Τρόπος πληρωμής» and
                     could not pay. A native radio always renders (styled when the
                     build is present, plain but functional when it is not). --}}
                <div>
                    <flux:text class="mb-2 font-medium">{{ __('portal.payment.method') }}</flux:text>
                    <div class="flex flex-col gap-2">
                        @foreach ($methods as $m)
                            <label class="flex cursor-pointer items-center gap-3 rounded-xl border border-zinc-200 bg-white p-4 has-[:checked]:border-zinc-900 has-[:checked]:ring-1 has-[:checked]:ring-zinc-900 dark:border-zinc-700 dark:bg-zinc-800 dark:has-[:checked]:border-white dark:has-[:checked]:ring-white">
                                <input type="radio" name="connection_id" value="{{ $m->id }}" required
                                    @checked((int) old('connection_id', $loop->first ? $m->id : null) === $m->id)
                                    class="h-4 w-4 accent-zinc-900 dark:accent-white" />
                                <span>{{ $m->label ?: $m->gateway }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>

                <flux:button type="submit" variant="primary" class="w-full">{{ __('portal.payment.continue') }}</flux:button>
            </form>

            {{-- «Χρήση πίστωσης»: money the customer already has with us, pointed at
                 one of their own documents. No new payment — a re-point, net-zero on
                 their balance — so it lives beside the pay form rather than inside
                 it. Shown only when there is credit AND something to settle. --}}

            @if ($openInvoices->isNotEmpty())
                <script>
                    // Progressive enhancement: when the customer picks a target, pre-fill
                    // the amount with that target's balance. Works without JS too — the
                    // server caps the amount at the invoice balance regardless.
                    (function () {
                        var sel = document.getElementById('pay-target');
                        var amount = document.querySelector('input[name="amount"]');
                        if (!sel || !amount) return;
                        sel.addEventListener('change', function () {
                            var opt = sel.options[sel.selectedIndex];
                            if (opt && opt.dataset.amount) amount.value = opt.dataset.amount;
                        });
                    })();
                </script>
            @endif
        @endif

        {{-- Outside the «no payment method» branch on purpose: applying existing
             credit is a re-point, not a charge, so it needs no gateway at all. --}}
        @if ($availableCredit > 0.005 && $openInvoices->isNotEmpty())
            <div class="mt-8 border-t border-zinc-200 pt-6 dark:border-zinc-700">
                <flux:heading size="sm">{{ __('portal.payment.use_credit') }}</flux:heading>
                <flux:text class="mt-1 text-sm text-zinc-500">
                    {{ __('portal.payment.use_credit_hint', ['amount' => Money::eur($availableCredit)]) }}
                </flux:text>

                <form method="POST" action="{{ route('portal.payment.apply-credit', $customer->id) }}"
                      class="mt-4 flex flex-col gap-4" data-credit-form>
                    @csrf
                    <div>
                            <flux:text class="text-sm font-medium">{{ __('portal.payment.what') }}</flux:text>
                            <flux:text class="mt-1 text-xs text-zinc-500">{{ __('portal.payment.use_credit_multi_hint') }}</flux:text>
                            <div class="mt-2 flex flex-col gap-2">
                                @foreach ($openInvoices as $inv)
                                    <label class="flex items-center gap-2 text-sm">
                                        <input type="checkbox" name="invoice_ids[]" value="{{ $inv->id }}"
                                               @checked($loop->first)
                                               class="rounded border-zinc-300 dark:border-zinc-600">
                                        <span>{{ $inv->invcode }} — {{ Money::eur((float) $inv->open_balance) }}</span>
                                        @if ($inv->isOffered())
                                            <flux:badge size="sm" color="amber">{{ __('portal.payment.proforma_badge') }}</flux:badge>
                                        @endif
                                    </label>
                                @endforeach
                            </div>
                        </div>

                    {{-- No amount field: the customer has ONE pot and picks documents, so the
                            allocator spends it in order (capped per document). Asking for a
                            figure as well only invites a number that will be silently reduced. --}}

                    <flux:button type="submit" variant="filled" class="w-full">
                        {{ __('portal.payment.use_credit_submit') }}
                    </flux:button>
                </form>
            </div>
        @endif

        <flux:text class="mt-6 text-sm">
            <flux:link href="{{ route('portal.statement') }}">{{ __('portal.payment.back_to_statement') }}</flux:link>
        </flux:text>
    </div>
</x-portal-layout>
