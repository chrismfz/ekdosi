<x-filament-panels::page>
    @php($r = $this->getResult())

    {{-- Period --}}
    <div class="flex flex-wrap gap-3">
        <label class="text-sm">
            <span class="block text-xs fi-color-gray mb-1">Από</span>
            <input type="date" wire:model.live="from"
                class="rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 px-2 py-1 text-sm">
        </label>
        <label class="text-sm">
            <span class="block text-xs fi-color-gray mb-1">Έως</span>
            <input type="date" wire:model.live="to"
                class="rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 px-2 py-1 text-sm">
        </label>
    </div>
    <div class="text-xs fi-color-gray mb-4 mt-1">Περίοδος: {{ $r['period_label'] }}</div>

    {{-- Totals --}}
    <div class="grid grid-cols-2 gap-3 md:grid-cols-4 mb-4">
        <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
            <div class="text-xs fi-color-gray">Εισπράξεις</div>
            <div class="text-lg font-bold text-success-600 dark:text-success-400">{{ $this->fmt($r['total_in']) }}</div>
        </div>
        <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
            <div class="text-xs fi-color-gray">Πληρωμές / επιστροφές</div>
            <div class="text-lg font-bold text-danger-600 dark:text-danger-400">{{ $this->fmt($r['total_out']) }}</div>
        </div>
        <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
            <div class="text-xs fi-color-gray">Καθαρή ταμειακή ροή</div>
            <div class="text-lg font-bold {{ $r['total_net'] > 0.005 ? 'text-success-600 dark:text-success-400' : ($r['total_net'] < -0.005 ? 'text-danger-600 dark:text-danger-400' : '') }}">
                {{ $this->fmt($r['total_net']) }}
            </div>
        </div>
        <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
            <div class="text-xs fi-color-gray">Κινήσεις</div>
            <div class="text-lg font-bold">{{ $r['total_count'] }}</div>
        </div>
    </div>

    <x-filament::section>
        @if (count($r['accounts']) === 0)
            <div class="text-center fi-color-gray py-6 text-sm">Καμία ταμειακή κίνηση στην περίοδο.</div>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs uppercase text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-white/10">
                            <th class="py-2 pr-4">Λογαριασμός / Τρόπος</th>
                            <th class="py-2 px-3 text-right">Εισπράξεις</th>
                            <th class="py-2 px-3 text-right">Πληρωμές</th>
                            <th class="py-2 px-3 text-right">Καθαρό</th>
                            <th class="py-2 pl-3 text-right">Πλήθος</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                        @foreach ($r['accounts'] as $acc)
                            {{-- Account subtotal row (bold header); methods break it down beneath. --}}
                            <tr @class(['border-t-2 border-gray-300 dark:border-white/20 font-semibold', 'bg-warning-50 dark:bg-warning-950/40' => $acc['account_id'] === null])>
                                <td class="py-2 pr-4">
                                    {{ $acc['account'] }}
                                    @if ($acc['account_id'] === null)
                                        <span class="text-xs fi-color-gray font-normal">(π.χ. μετρητά/χωρίς τραπεζικό λογαριασμό)</span>
                                    @endif
                                </td>
                                <td class="py-2 px-3 text-right font-mono whitespace-nowrap text-success-600 dark:text-success-400">{{ $this->fmt($acc['in']) }}</td>
                                <td class="py-2 px-3 text-right font-mono whitespace-nowrap text-danger-600 dark:text-danger-400">{{ $this->fmt($acc['out']) }}</td>
                                <td class="py-2 px-3 text-right font-mono whitespace-nowrap {{ $acc['net'] > 0.005 ? 'text-success-600 dark:text-success-400' : ($acc['net'] < -0.005 ? 'text-danger-600 dark:text-danger-400' : '') }}">{{ $this->fmt($acc['net']) }}</td>
                                <td class="py-2 pl-3 text-right font-mono">{{ $acc['count'] }}</td>
                            </tr>
                            @foreach ($acc['methods'] as $m)
                                <tr>
                                    <td class="py-2 pr-4 pl-6 fi-color-gray">{{ $m['method'] }}</td>
                                    <td class="py-2 px-3 text-right font-mono whitespace-nowrap">{{ $this->fmt($m['in']) }}</td>
                                    <td class="py-2 px-3 text-right font-mono whitespace-nowrap fi-color-gray">{{ $this->fmt($m['out']) }}</td>
                                    <td class="py-2 px-3 text-right font-mono whitespace-nowrap">{{ $this->fmt($m['net']) }}</td>
                                    <td class="py-2 pl-3 text-right font-mono">{{ $m['count'] }}</td>
                                </tr>
                            @endforeach
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="border-t-2 border-gray-300 dark:border-white/20 font-bold">
                            <td class="py-2 pr-4">ΣΥΝΟΛΟ</td>
                            <td class="py-2 px-3 text-right font-mono whitespace-nowrap">{{ $this->fmt($r['total_in']) }}</td>
                            <td class="py-2 px-3 text-right font-mono whitespace-nowrap">{{ $this->fmt($r['total_out']) }}</td>
                            <td class="py-2 px-3 text-right font-mono whitespace-nowrap {{ $r['total_net'] > 0.005 ? 'text-success-600 dark:text-success-400' : ($r['total_net'] < -0.005 ? 'text-danger-600 dark:text-danger-400' : '') }}">{{ $this->fmt($r['total_net']) }}</td>
                            <td class="py-2 pl-3 text-right font-mono">{{ $r['total_count'] }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <div class="mt-3 text-xs fi-color-gray leading-relaxed">
                Ταμειακές κινήσεις πελατών ανά λογαριασμό/ταμείο και τρόπο πληρωμής: εισπράξεις (χρήματα μέσα) έναντι
                πληρωμών/επιστροφών (χρήματα έξω)· καθαρό = εισπράξεις − πληρωμές. Ημερομηνία = ημ/νία πληρωμής
                (ή, αν λείπει, η ημ/νία καταχώρισης). Τα έξοδα/πληρωμές προμηθευτών είναι στο Βιβλίο Εσόδων-Εξόδων.
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
