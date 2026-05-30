@php
    $d = $this->getHeatmap();
    $max = $d['max'] ?? 0.0;
@endphp

<x-filament::section>
    <x-slot name="heading">Θερμικός χάρτης μήνα × έτους (καθαρά)</x-slot>
    <x-slot name="description">Πιο σκούρο = μεγαλύτερος τζίρος. Δείχνει την εποχικότητα: ποιοι μήνες είναι σταθερά δυνατοί/αδύναμοι.</x-slot>

    @if (empty($d['years']))
        <p class="text-sm text-gray-500 dark:text-gray-400">Δεν υπάρχουν δεδομένα.</p>
    @else
        <div class="overflow-x-auto">
            <table class="w-full text-center text-xs">
                <thead>
                    <tr class="text-gray-500 dark:text-gray-400">
                        <th class="px-2 py-1 text-left font-medium">Έτος</th>
                        @foreach ($d['months'] as $month)
                            <th class="px-2 py-1 font-medium">{{ $month }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($d['years'] as $year)
                        <tr>
                            <td class="px-2 py-1 text-left font-semibold text-gray-700 dark:text-gray-200">{{ $year }}</td>
                            @for ($m = 1; $m <= 12; $m++)
                                @php
                                    $value = $d['matrix'][$year][$m] ?? 0.0;
                                    // Alpha 0..0.9 scaled by the largest cell; a faint floor so
                                    // non-zero months are still visible.
                                    $alpha = $max > 0 ? round(min(0.9, 0.08 + ($value / $max) * 0.82), 3) : 0;
                                @endphp
                                <td
                                    class="px-2 py-1 tabular-nums text-gray-800 dark:text-gray-100"
                                    style="background-color: rgba(16, 185, 129, {{ $value > 0 ? $alpha : 0 }});"
                                    title="{{ $d['months'][$m - 1] }} {{ $year }}: {{ \App\Support\Money::eur($value) }}"
                                >
                                    {{ $value > 0 ? number_format($value, 0, ',', '.') : '—' }}
                                </td>
                            @endfor
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-filament::section>
