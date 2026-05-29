{{--
    Shared reconciliation table for the myDATA console.

    Inputs:
      $rows    : list<array> — serialized ReconciliationRow arrays.
      $columns : list<string> — ordered column keys from the vocabulary below.

    Column vocabulary (key → header):
      invcode→Κωδικός  mark→ΜΑΡΚ  issuedAt→Έκδοση  counterpart→Πελάτης
      gross→Σύνολο  localState→Τοπικά  aadeState→AADE  mydataState→myDATA
      state→Κατάσταση  problem→Πρόβλημα  linkStatus→Σύνδεση  open→(άνοιγμα)
--}}
@php
    $money = fn ($v) => $v === null ? '—' : '€ ' . number_format((float) $v, 2, ',', '.');

    $headers = [
        'invcode' => 'Κωδικός',
        'mark' => 'ΜΑΡΚ',
        'issuedAt' => 'Έκδοση',
        'counterpart' => 'Πελάτης',
        'supplier' => 'Προμηθευτής',
        'afm' => 'ΑΦΜ',
        'gross' => 'Σύνολο',
        'localState' => 'Τοπικά',
        'aadeState' => 'AADE',
        'mydataState' => 'myDATA',
        'state' => 'Κατάσταση',
        'problem' => 'Πρόβλημα',
        'linkStatus' => 'Σύνδεση',
        'open' => '',
    ];
    $rightAligned = ['gross'];
@endphp

<div class="overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="text-left text-gray-500 dark:text-gray-400">
            <tr class="border-b border-gray-200 dark:border-white/10">
                @foreach ($columns as $col)
                    <th @class([
                        'py-2',
                        'pr-4' => $col !== 'open',
                        'text-right' => in_array($col, $rightAligned, true),
                    ])>{{ $headers[$col] ?? '' }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $row)
                <tr class="border-b border-gray-100 dark:border-white/5">
                    @foreach ($columns as $col)
                        @switch($col)
                            @case('invcode')
                                <td class="py-2 pr-4 font-medium">{{ $row['invcode'] ?? '—' }}</td>
                                @break
                            @case('mark')
                                <td class="py-2 pr-4 font-mono text-xs">
                                    @if (! empty($row['markUrl']))
                                        <x-filament::link :href="$row['markUrl']" size="sm" class="font-mono">{{ $row['mark'] }}</x-filament::link>
                                    @else
                                        {{ $row['mark'] }}
                                    @endif
                                </td>
                                @break
                            @case('issuedAt')
                                <td class="py-2 pr-4 whitespace-nowrap">{{ $row['issuedAt'] ?? '—' }}</td>
                                @break
                            @case('counterpart')
                            @case('supplier')
                                <td class="py-2 pr-4">{{ $row['counterpartName'] ?? '—' }}</td>
                                @break
                            @case('afm')
                                <td class="py-2 pr-4 font-mono text-xs">{{ $row['afm'] ?? '—' }}</td>
                                @break
                            @case('gross')
                                <td class="py-2 pr-4 text-right whitespace-nowrap">{{ $money($row['gross']) }}</td>
                                @break
                            @case('localState')
                                <td class="py-2 pr-4">
                                    @if ($row['localState'])
                                        <x-filament::badge :color="$row['localState'] === 'CANCELLED' ? 'danger' : 'success'">{{ $row['localState'] }}</x-filament::badge>
                                    @else
                                        <span class="text-gray-400">—</span>
                                    @endif
                                </td>
                                @break
                            @case('aadeState')
                            @case('mydataState')
                            @case('state')
                                <td class="py-2 pr-4">
                                    @if ($row['aadeState'])
                                        <x-filament::badge :color="$row['aadeState'] === 'CANCELLED' ? 'danger' : 'success'">{{ $row['aadeState'] }}</x-filament::badge>
                                    @else
                                        <span class="text-gray-400">—</span>
                                    @endif
                                </td>
                                @break
                            @case('problem')
                                <td class="py-2 pr-4 text-gray-600 dark:text-gray-300">{{ $row['problem'] }}</td>
                                @break
                            @case('linkStatus')
                                <td class="py-2 pr-4">
                                    @if ($row['problem'])
                                        <x-filament::badge color="warning">Διαφορά κατάστασης</x-filament::badge>
                                    @else
                                        <x-filament::badge color="success">Συνδεδεμένο</x-filament::badge>
                                    @endif
                                </td>
                                @break
                            @case('open')
                                <td class="py-2">
                                    @if ($row['url'])
                                        <x-filament::link :href="$row['url']" size="sm">Άνοιγμα</x-filament::link>
                                    @endif
                                </td>
                                @break
                        @endswitch
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
