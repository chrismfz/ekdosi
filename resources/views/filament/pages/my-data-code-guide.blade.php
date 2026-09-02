<x-filament-panels::page>
    <div class="text-sm text-gray-500 dark:text-gray-400 mb-4 leading-relaxed">
        Επεξήγηση των κωδικών myDATA (ΑΑΔΕ) που συναντάς στην εφαρμογή: τι είναι ο καθένας και πού
        χρησιμοποιείται. Δεν χρειάζεται να τους ξέρεις απέξω — ψάξε εδώ. Οι φόρμες (τύπος παραστατικού,
        κατηγορία ΦΠΑ, κατηγορία εσόδων) παραπέμπουν σε αυτή τη σελίδα.
    </div>

    @foreach ($this->sections() as $section)
        <x-filament::section :heading="$section['title']" collapsible>
            <div class="text-sm text-gray-500 dark:text-gray-400 mb-3 leading-relaxed">
                {{ $section['intro'] }}
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                        @foreach ($section['rows'] as $row)
                            <tr>
                                <td class="py-2 pr-4 align-top font-mono text-gray-800 dark:text-gray-100">
                                    {{ $row['code'] }}
                                </td>
                                <td class="py-2 pr-4 align-top font-semibold text-gray-800 dark:text-gray-100">
                                    {{ $row['title'] }}
                                </td>
                                <td class="py-2 align-top text-gray-600 dark:text-gray-400 leading-relaxed">
                                    {{ $row['detail'] }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    @endforeach
</x-filament-panels::page>
