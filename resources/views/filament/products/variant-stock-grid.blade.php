@php
    $fmt = fn (?float $n) => \App\Support\Products\StockDisplay::format($n);
    $css = fn (array $cell) => ['danger' => 'is-negative', 'warning' => 'is-out', 'success' => 'is-in'][$cell['tone']] ?? '';
@endphp

@if ($grid['mode'] === 'empty')
    <p class="text-sm text-gray-500">Δεν υπάρχουν ακόμη παραλλαγές — «Δημιουργία παραλλαγών» στην καρτέλα «Παραλλαγές» παρακάτω.</p>
@elseif ($grid['mode'] === 'matrix')
    <div class="ekdosi-vgrid-wrap">
        <table class="ekdosi-vgrid">
            <thead>
                <tr>
                    <th></th>
                    @foreach ($grid['cols'] as $col)
                        <th>{{ $col }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($grid['rows'] as $row)
                    <tr>
                        <th class="ekdosi-vgrid-rowhead">
                            @if ($row['hex'])
                                <span class="ekdosi-swatch" style="background: {{ $row['hex'] }}"></span>
                            @endif
                            {{ $row['label'] }}
                        </th>
                        @foreach ($row['cells'] as $cell)
                            @if ($cell === null)
                                <td class="is-none">·</td>
                            @else
                                <td class="{{ $css($cell) }} {{ $cell['active'] ? '' : 'is-inactive' }}">{{ $fmt($cell['stock']) }}</td>
                            @endif
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@else
    <div class="ekdosi-vgrid-wrap">
        <table class="ekdosi-vgrid">
            <tbody>
                @foreach ($grid['items'] as $item)
                    <tr>
                        <th class="ekdosi-vgrid-rowhead">{{ $item['label'] }}</th>
                        <td class="{{ $css($item) }} {{ $item['active'] ? '' : 'is-inactive' }}">{{ $fmt($item['stock']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif

@if ($grid['mode'] !== 'empty')
    <p class="text-sm text-gray-500">Σύνολο: <strong>{{ $fmt($grid['total']) }}</strong> · «·» = δεν υπάρχει αυτός ο συνδυασμός · διαγραμμισμένο = ανενεργή παραλλαγή</p>
@endif
