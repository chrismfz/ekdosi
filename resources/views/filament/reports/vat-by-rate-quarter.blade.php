@php
    $d = $this->getVatTable();
    $rateKey = $d['rateKey'];
    // Data is ascending (0,6,13,24); show highest rate first (24 top-down).
    $rates = array_reverse($d['rates']);
    $quarters = [1 => 'Α΄ τρίμηνο', 2 => 'Β΄ τρίμηνο', 3 => 'Γ΄ τρίμηνο', 4 => 'Δ΄ τρίμηνο'];
    $hasData = ! empty($d['rates']);
    $fmt = fn ($v) => number_format((float) $v, 2, ',', '.');
    $ratePct = fn ($r) => rtrim(rtrim(number_format((float) $r, 2, ',', '.'), '0'), ',').'%';
@endphp

<x-filament::section>
    <x-slot name="heading">ΦΠΑ εκροών ανά συντελεστή &amp; τρίμηνο — {{ $d['year'] }}</x-slot>
    <x-slot name="description">Βοηθητικός πίνακας για την περιοδική δήλωση ΦΠΑ: φορολογητέα βάση + ΦΠΑ ανά συντελεστή, ανά τρίμηνο (καθαρά από πιστωτικά). Δεν αποτελεί επίσημη δήλωση.</x-slot>

    @if (! $hasData)
        <p class="text-sm text-gray-500 dark:text-gray-400">Δεν υπάρχουν παραστατικά για το {{ $d['year'] }}.</p>
    @else
        <div class="overflow-x-auto">
            <table class="w-full text-xs tabular-nums">
                <thead>
                    <tr class="text-gray-500 dark:text-gray-400">
                        <th class="px-2 py-1 text-left font-medium">Συντελεστής</th>
                        @foreach ($quarters as $label)
                            <th class="px-2 py-1 text-right font-medium">{{ $label }}</th>
                        @endforeach
                        <th class="px-2 py-1 text-right font-semibold">Σύνολο έτους</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rates as $rate)
                        @php $key = $rateKey($rate); @endphp
                        <tr class="border-t border-gray-100 dark:border-gray-800">
                            <td class="px-2 py-1 text-left font-semibold text-gray-700 dark:text-gray-200 whitespace-nowrap">{{ $ratePct($rate) }}</td>
                            @foreach ([1, 2, 3, 4] as $q)
                                @php $cell = $d['quarters'][$q]['rates'][$key] ?? ['net' => 0, 'vat' => 0]; @endphp
                                <td class="px-2 py-1 text-right text-gray-800 dark:text-gray-100">
                                    <div class="font-semibold">{{ $fmt($cell['vat']) }}</div>
                                    <div class="text-gray-400 dark:text-gray-500">{{ $fmt($cell['net']) }}</div>
                                </td>
                            @endforeach
                            @php $tot = $d['totals']['rates'][$key] ?? ['net' => 0, 'vat' => 0]; @endphp
                            <td class="px-2 py-1 text-right text-gray-900 dark:text-gray-100">
                                <div class="font-semibold">{{ $fmt($tot['vat']) }}</div>
                                <div class="text-gray-400 dark:text-gray-500">{{ $fmt($tot['net']) }}</div>
                            </td>
                        </tr>
                    @endforeach
                    <tr class="border-t border-gray-300 dark:border-gray-600">
                        <td class="px-2 py-1 text-left font-semibold text-gray-700 dark:text-gray-200">Σύνολο ΦΠΑ</td>
                        @foreach ([1, 2, 3, 4] as $q)
                            <td class="px-2 py-1 text-right font-semibold text-gray-900 dark:text-gray-100">{{ $fmt($d['quarters'][$q]['vat']) }}</td>
                        @endforeach
                        <td class="px-2 py-1 text-right font-semibold text-gray-900 dark:text-gray-100">{{ $fmt($d['totals']['vat']) }}</td>
                    </tr>
                </tbody>
            </table>
            <p class="mt-2 text-gray-400 dark:text-gray-500" style="font-size: 0.7rem;">Σε κάθε κελί — πάνω: ΦΠΑ · κάτω: καθαρή αξία (φορολογητέα βάση).</p>
        </div>
    @endif
</x-filament::section>
