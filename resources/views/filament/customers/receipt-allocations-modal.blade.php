@php
    /**
     * Φ3 — read-only drill-down for one grouped «έμβασμα/είσπραξη» ledger row.
     *
     * @var list<array{label: string, amount: float, invoice_id: ?int, invcode: ?string}> $allocations
     * @var float $total
     * @var ?string $channel        provenance («από πού ήρθε») — shared across the group
     * @var ?string $transactionId  shared txn id (null when members differ → hidden)
     * @var ?string $method         shared payment method (null when members differ → hidden)
     * @var \Closure $fmtMoney
     */
@endphp

<div class="space-y-3 text-sm">
    {{-- «Από πού ήρθε» — provenance/channel, so the operator doesn't have to dig
         into the Πληρωμές menu to see how a receipt arrived. --}}
    @if (! empty($channel) || ! empty($method) || ! empty($transactionId))
        <dl class="grid grid-cols-1 gap-2 rounded-lg border border-gray-200 dark:border-white/10 p-3 md:grid-cols-3">
            @if (! empty($channel))
                <div>
                    <dt class="text-xs text-gray-500 dark:text-gray-400">Κανάλι / προέλευση</dt>
                    <dd class="mt-0.5">{{ $channel }}</dd>
                </div>
            @endif
            @if (! empty($method))
                <div>
                    <dt class="text-xs text-gray-500 dark:text-gray-400">Τρόπος πληρωμής</dt>
                    <dd class="mt-0.5">{{ $method }}</dd>
                </div>
            @endif
            @if (! empty($transactionId))
                <div>
                    <dt class="text-xs text-gray-500 dark:text-gray-400">Κωδικός συναλλαγής</dt>
                    <dd class="mt-0.5 font-mono break-all">{{ $transactionId }}</dd>
                </div>
            @endif
        </dl>
    @endif

    @if (empty($allocations))
        <p class="text-gray-500 dark:text-gray-400">Δεν υπάρχουν αναλυτικές κινήσεις για αυτή την είσπραξη.</p>
    @else
        <table class="w-full">
            <thead>
                <tr class="text-left text-xs uppercase text-gray-500 dark:text-gray-400">
                    <th class="py-1 pr-4">Παραστατικό</th>
                    <th class="py-1 text-right">Ποσό</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                @foreach ($allocations as $a)
                    <tr>
                        <td class="py-1.5 pr-4">
                            @if (! empty($a['invoice_id']))
                                <span class="font-medium text-primary-600 dark:text-primary-400">{{ $a['label'] }}</span>
                            @else
                                <span class="text-gray-600 dark:text-gray-300">{{ $a['label'] }}</span>
                            @endif
                        </td>
                        <td class="py-1.5 text-right font-mono">{{ $fmtMoney($a['amount']) }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr class="border-t-2 border-gray-200 dark:border-white/20 font-semibold">
                    <td class="py-1.5 pr-4">Σύνολο είσπραξης</td>
                    <td class="py-1.5 text-right font-mono">{{ $fmtMoney($total) }}</td>
                </tr>
            </tfoot>
        </table>
    @endif
</div>
