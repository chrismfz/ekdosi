@php
    /**
     * Φ3 — read-only drill-down for one grouped «έμβασμα/είσπραξη» ledger row.
     *
     * @var list<array{label: string, amount: float, invoice_id: ?int, invcode: ?string}> $allocations
     * @var float $total
     * @var \Closure $fmtMoney
     */
@endphp

<div class="space-y-3 text-sm">
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
