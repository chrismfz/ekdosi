<x-filament-panels::page>
    @php
        $mapping = $this->getMapping();
        $chart = $this->getChart();
    @endphp

    <x-filament::section>
        <x-slot name="heading">Αντιστοίχιση κατηγορίας myDATA → λογαριασμού</x-slot>
        <x-slot name="description">
            Ενδεικτική, προεπιλεγμένη αντιστοίχιση (επίπεδο ομάδας ΕΓΛΣ) — προσανατολισμός, όχι
            υποκατάστατο των βιβλίων του λογιστή. Η νόμιμη ταξινόμηση παραμένει η κατηγορία myDATA.
        </x-slot>

        @foreach (['income' => 'Έσοδα (category1_x)', 'expense' => 'Έξοδα (category2_x)'] as $side => $heading)
            <h3 class="mt-4 mb-2 font-semibold text-gray-700 dark:text-gray-300">{{ $heading }}</h3>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-left text-gray-500 dark:border-gray-700 dark:text-gray-400">
                            <th class="py-2 pr-4">Κωδικός</th>
                            <th class="py-2 pr-4">Κατηγορία (myDATA)</th>
                            <th class="py-2 pr-4">Λογαριασμός</th>
                            <th class="py-2">Ονομασία λογαριασμού</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($mapping[$side] as $row)
                            <tr class="border-b border-gray-100 dark:border-gray-800">
                                <td class="py-2 pr-4 whitespace-nowrap text-xs text-gray-400">{{ $row['category'] }}</td>
                                <td class="py-2 pr-4">{{ $row['categoryLabel'] ?? '—' }}</td>
                                <td class="py-2 pr-4 font-medium">{{ $row['code'] }}</td>
                                <td class="py-2">{{ $row['name'] ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endforeach
    </x-filament::section>

    <x-filament::section collapsible collapsed>
        <x-slot name="heading">Πλήρης χάρτης λογαριασμών (ΕΓΛΣ — υποσύνολο)</x-slot>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-left text-gray-500 dark:border-gray-700 dark:text-gray-400">
                        <th class="py-2 pr-4">Κωδικός</th>
                        <th class="py-2">Ονομασία</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($chart as $account)
                        <tr class="border-b border-gray-100 dark:border-gray-800">
                            <td class="py-2 pr-4 font-medium whitespace-nowrap">{{ $account['code'] }}</td>
                            <td class="py-2">{{ $account['name'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
