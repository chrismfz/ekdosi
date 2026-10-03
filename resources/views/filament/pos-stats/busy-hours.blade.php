<x-filament-widgets::widget>
    <x-filament::section :heading="$title">
        <style>
            .ph-wrap { overflow-x:auto; }
            .ph-table { border-collapse:collapse; font-size:.8rem; width:100%; }
            .ph-table th, .ph-table td { padding:.3rem .35rem; text-align:center; white-space:nowrap; }
            .ph-table th { color:#6b7280; font-weight:600; }
            .ph-table td.ph-day { text-align:left; font-weight:600; color:#374151; }
            .dark .ph-table td.ph-day { color:#d1d5db; }
            .ph-cell { border-radius:.3rem; font-variant-numeric:tabular-nums; }
            .ph-empty { color:#6b7280; padding:1rem; text-align:center; }
        </style>
        @if ($grid['hours'] === [])
            <div class="ph-empty">Καμία απόδειξη τον μήνα αυτό.</div>
        @else
            @php($days = [1 => 'Δευ', 2 => 'Τρί', 3 => 'Τετ', 4 => 'Πέμ', 5 => 'Παρ', 6 => 'Σάβ', 7 => 'Κυρ'])
            <div class="ph-wrap">
                <table class="ph-table">
                    <thead><tr><th></th>@foreach ($grid['hours'] as $h)<th>{{ sprintf('%02d', $h) }}</th>@endforeach</tr></thead>
                    <tbody>
                        @foreach ($days as $d => $label)
                            <tr>
                                <td class="ph-day">{{ $label }}</td>
                                @foreach ($grid['hours'] as $h)
                                    @php($n = $grid['receipts'][$d][$h] ?? 0)
                                    @php($a = $max > 0 ? round(0.08 + 0.72 * $n / $max, 2) : 0)
                                    <td class="ph-cell" style="background: rgba(59,130,246,{{ $n ? $a : 0 }})"
                                        title="{{ $label }} {{ sprintf('%02d', $h) }}:00 — {{ $n }} αποδείξεις · {{ number_format($grid['net'][$d][$h] ?? 0, 2, ',', '.') }} €">{{ $n ?: '' }}</td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
