<x-filament-panels::page>
    @php
        $color = ['ok' => 'success', 'warn' => 'warning', 'fail' => 'danger'];
        $icon = ['ok' => '✓', 'warn' => '⚠', 'fail' => '✗'];
        $label = ['ok' => 'Έτοιμο', 'warn' => 'Προσοχή', 'fail' => 'Μπλόκο'];
    @endphp

    <div class="text-sm text-gray-500 dark:text-gray-400 mb-4 leading-relaxed">
        Έλεγχος «είμαι νόμιμος;» ανά εταιρεία: ρυθμίσεις myDATA (τύποι/ΦΠΑ/§8.3/§8.12), πίνακες (lookups),
        προϊόντα και προαιρετικές αντιστοιχίσεις WHMCS. <strong>Μπλόκο</strong> = η ΑΑΔΕ θα απέρριπτε·
        <strong>Προσοχή</strong> = προς έλεγχο (δεν μπλοκάρει). Για τους κωδικούς, πάτησε «Οδηγός κωδικών».
    </div>

    <div class="space-y-3">
        @foreach ($report as $company)
            @php $cc = $color[$company['status']]; @endphp
            <x-filament::section>
                <x-slot name="heading">
                    <span class="text-{{ $cc }}-700 dark:text-{{ $cc }}-400 font-semibold">
                        {{ $icon[$company['status']] }} {{ $company['name'] }} — {{ $label[$company['status']] }}
                    </span>
                </x-slot>

                <div class="space-y-3">
                    @foreach ($company['sections'] as $section)
                        @php $sc = $color[$section['status']]; @endphp
                        <div>
                            <div class="text-xs uppercase font-semibold text-{{ $sc }}-700 dark:text-{{ $sc }}-400 mb-1">
                                {{ $icon[$section['status']] }} {{ $section['label'] }}
                            </div>
                            <ul class="text-sm space-y-1">
                                @foreach ($section['items'] as $item)
                                    @php $ic = $color[$item['status']]; @endphp
                                    <li class="text-{{ $ic }}-700 dark:text-{{ $ic }}-400">
                                        {{ $icon[$item['status']] }} {{ $item['message'] }}
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endforeach
                </div>
            </x-filament::section>
        @endforeach
    </div>
</x-filament-panels::page>
