<x-filament-panels::page>
    @php($r = $this->getResult())

    <div class="flex gap-3 mb-4">
        <label class="text-sm">
            <span class="block text-xs fi-color-gray mb-1">Έτος</span>
            <select wire:model.live="year"
                class="rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 px-2 py-1 text-sm">
                @foreach ($this->availableYears() as $y)
                    <option value="{{ $y }}">{{ $y }}</option>
                @endforeach
            </select>
        </label>
    </div>

    <div class="grid grid-cols-2 gap-3 md:grid-cols-4 mb-4">
        <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
            <div class="text-xs fi-color-gray">Παρακρατήσεις {{ $r['year'] }}</div>
            <div class="text-lg font-bold">{{ $this->fmt($r['total_withheld']) }}</div>
        </div>
        <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
            <div class="text-xs fi-color-gray">Καθαρή αξία τιμολογίων</div>
            <div class="text-lg font-bold">{{ $this->fmt($r['total_net']) }}</div>
        </div>
        <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
            <div class="text-xs fi-color-gray">Παραστατικά</div>
            <div class="text-lg font-bold">{{ $r['total_docs'] }}</div>
        </div>
        <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
            <div class="text-xs fi-color-gray">Πελάτες</div>
            <div class="text-lg font-bold">{{ count($r['by_customer']) }}</div>
        </div>
    </div>

    @if ($r['total_docs'] === 0)
        <x-filament::section>
            <div class="text-center fi-color-gray py-6 text-sm">Δεν βρέθηκαν παραστατικά με παρακράτηση φόρου για το {{ $r['year'] }}.</div>
        </x-filament::section>
    @else
        <x-filament::section heading="Ανά πελάτη" class="mb-4">
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs uppercase text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-white/10">
                            <th class="py-2 pr-4">Πελάτης</th>
                            <th class="py-2 px-3">ΑΦΜ</th>
                            <th class="py-2 px-3 text-right">Παραστατικά</th>
                            <th class="py-2 px-3 text-right">Καθαρή αξία</th>
                            <th class="py-2 pl-3 text-right">Παρακράτηση</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                        @foreach ($r['by_customer'] as $c)
                            <tr>
                                <td class="py-2 pr-4 font-medium">{{ $c['customer'] ?? '—' }}</td>
                                <td class="py-2 px-3 font-mono">{{ $c['afm'] ?? '—' }}</td>
                                <td class="py-2 px-3 text-right font-mono">{{ $c['docs'] }}</td>
                                <td class="py-2 px-3 text-right font-mono whitespace-nowrap">{{ $this->fmt($c['net']) }}</td>
                                <td class="py-2 pl-3 text-right font-mono whitespace-nowrap font-bold">{{ $this->fmt($c['withheld']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>

        <x-filament::section heading="Παραστατικά" class="mb-4">
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs uppercase text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-white/10">
                            <th class="py-2 pr-4">Ημερομηνία</th>
                            <th class="py-2 px-3">Παραστατικό</th>
                            <th class="py-2 px-3">Πελάτης</th>
                            <th class="py-2 px-3 text-right">Καθαρή αξία</th>
                            <th class="py-2 px-3 text-right">Μεικτό</th>
                            <th class="py-2 px-3 text-right">%</th>
                            <th class="py-2 pl-3 text-right">Παρακράτηση</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                        @foreach ($r['documents'] as $d)
                            <tr>
                                <td class="py-2 pr-4 whitespace-nowrap">{{ $d['date'] }}</td>
                                <td class="py-2 px-3 font-medium whitespace-nowrap">
                                    @if ($url = $this->invoiceUrl($d['id']))
                                        <a href="{{ $url }}" class="text-primary-600 hover:underline dark:text-primary-400">{{ $d['doc'] }}</a>
                                    @else
                                        {{ $d['doc'] }}
                                    @endif
                                    @if ($d['is_credit'])
                                        <span class="text-xs fi-color-gray">(πιστωτικό)</span>
                                    @endif
                                </td>
                                <td class="py-2 px-3">{{ $d['customer'] ?? '—' }}</td>
                                <td class="py-2 px-3 text-right font-mono whitespace-nowrap">{{ $this->fmt($d['net']) }}</td>
                                <td class="py-2 px-3 text-right font-mono whitespace-nowrap">{{ $this->fmt($d['gross']) }}</td>
                                <td class="py-2 px-3 text-right font-mono">{{ $d['rate'] !== null ? rtrim(rtrim(number_format($d['rate'], 2, ',', '.'), '0'), ',') : '—' }}</td>
                                <td class="py-2 pl-3 text-right font-mono whitespace-nowrap font-bold">{{ $this->fmt($d['withheld']) }}</td>
                            </tr>
                        @endforeach
                        <tr class="border-t-2 border-gray-300 font-bold">
                            <td class="py-2 pr-4" colspan="3">Σύνολο</td>
                            <td class="py-2 px-3 text-right font-mono whitespace-nowrap">{{ $this->fmt($r['total_net']) }}</td>
                            <td></td>
                            <td></td>
                            <td class="py-2 pl-3 text-right font-mono whitespace-nowrap">{{ $this->fmt($r['total_withheld']) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </x-filament::section>

        <div class="mt-3 text-xs fi-color-gray leading-relaxed">
            Ο φόρος που παρακράτησαν οι πελάτες στα τιμολόγιά μας (εκδομένα, όχι ακυρωμένα· τα πιστωτικά αφαιρούνται). Το
            σύνολο είναι το ίδιο με τη γραμμή «Παρακρατήσεις φόρου στα τιμολόγιά μας» στα «Φορολογικά». Το «Ανά πελάτη»
            συγκρίνεται με τη βεβαίωση παρακράτησης που στέλνει κάθε πελάτης. Το % είναι ενδεικτικό (παρακράτηση / καθαρή αξία).
        </div>
    @endif
</x-filament-panels::page>
