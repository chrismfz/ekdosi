<x-filament-panels::page>
    @php($e = $this->estimate())
    @php($v = $this->vat())
    @php($p = $e['profile'])
    @php($payroll = collect($e['expense_breakdown'])->whereIn('bucket', ['payroll', 'social_security']))

    <div class="flex gap-3 mb-4 items-center">
        <label class="text-sm">
            <span class="block text-xs fi-color-gray mb-1">Έτος</span>
            <select wire:model.live="year"
                class="rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 px-2 py-1 text-sm">
                @foreach ($this->availableYears() as $y)
                    <option value="{{ $y }}">{{ $y }}</option>
                @endforeach
            </select>
        </label>
        <div class="text-xs fi-color-gray">
            Στοιχεία έως {{ \Illuminate\Support\Carbon::parse($e['through'])->format('d/m/Y') }} · τοπικά δεδομένα (παραστατικά + έξοδα myDATA)
        </div>
    </div>

    {{-- KPI row --}}
    <div class="grid grid-cols-2 gap-3 md:grid-cols-4 mb-4">
        <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
            <div class="text-xs fi-color-gray">ΦΠΑ προς απόδοση {{ $this->year }}</div>
            <div class="text-lg font-bold">{{ $this->fmt($v['payable_total']) }}</div>
            <div class="text-xs fi-color-gray">Εκροών {{ $this->fmt($v['year']->outputVat) }} · Εισροών {{ $this->fmt($v['year']->inputVat) }}</div>
        </div>
        <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
            @php($hp = $e['headline_payable'])
            <div class="text-xs fi-color-gray">Φόρος εισοδήματος · υπόλοιπο (εκτίμηση{{ $e['is_current'] ? ', προβολή 31/12' : '' }})</div>
            @if ($hp === null)
                <div class="text-lg font-bold fi-color-gray">—</div>
                <div class="text-xs fi-color-gray">Λίγα δεδομένα ακόμα (&lt; {{ \App\Services\Accounting\IncomeTaxEstimate::MIN_PROJECTION_DAYS }} ημέρες)</div>
            @else
                <div class="text-lg font-bold {{ $hp < 0 ? 'text-success-600 dark:text-success-400' : '' }}">{{ $this->fmt($hp) }}</div>
                <div class="text-xs fi-color-gray">
                    @if ($hp > 0 && $e['monthly_saving'] !== null) Αποταμίευση {{ $this->fmt($e['monthly_saving']) }}/μήνα έως 31/12 @elseif ($hp > 0) Πληρωτέο με τη δήλωση {{ $this->year + 1 }} @elseif ($hp < 0) Προς επιστροφή/συμψηφισμό @else — @endif
                </div>
            @endif
        </div>
        <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
            <div class="text-xs fi-color-gray">Καθαρό αποτέλεσμα {{ $this->year }}</div>
            <div class="text-lg font-bold">{{ $this->fmt($e['profit']) }}</div>
            <div class="text-xs fi-color-gray">Έσοδα {{ $this->fmt($e['income_total']) }} · Έξοδα {{ $this->fmt($e['expense_total']) }}</div>
        </div>
        <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
            <div class="text-xs fi-color-gray">Μισθοδοσία + ΕΦΚΑ {{ $this->year }}</div>
            <div class="text-lg font-bold">{{ $this->fmt((float) $payroll->sum('net')) }}</div>
            <div class="text-xs fi-color-gray">
                @if ($payroll->isEmpty()) Δεν έχουν περαστεί εγγραφές @else Τελευταία εγγραφή {{ \Illuminate\Support\Carbon::parse($payroll->max('last_date'))->format('d/m/Y') }} @endif
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-3 mb-4">
        {{-- Income tax estimate --}}
        <x-filament::section heading="Εκτίμηση φόρου εισοδήματος" class="lg:col-span-2">
            @php($proj = $e['projection'])
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="fi-color-gray text-xs">
                            <th class="text-left py-1"></th>
                            <th class="text-right py-1">{{ $e['is_current'] ? 'Μέχρι σήμερα' : $this->year }}</th>
                            @if ($proj)<th class="text-right py-1">Προβολή 31/12</th>@endif
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        <tr><td class="py-1">Έσοδα (καθαρά)</td><td class="text-right">{{ $this->fmt($e['income_total']) }}</td>@if ($proj)<td class="text-right fi-color-gray">{{ $this->fmt($proj['income_total']) }}</td>@endif</tr>
                        @if (abs($e['income_adjustments']) > 0.004)
                            <tr><td class="py-1 pl-6 text-xs fi-color-gray">εκ των οποίων τακτοποιήσεις εσόδων (17.3/17.4)</td><td class="text-right text-xs fi-color-gray">{{ $this->fmt($e['income_adjustments']) }}</td>@if ($proj)<td></td>@endif</tr>
                        @endif
                        <tr><td class="py-1">Έξοδα (μαζί με μισθοδοσία, ΕΦΚΑ, αποσβέσεις)</td><td class="text-right">− {{ $this->fmt($e['expense_total']) }}</td>@if ($proj)<td class="text-right fi-color-gray">− {{ $this->fmt($proj['expense_total']) }}</td>@endif</tr>
                        @if (abs($e['capex']) > 0.004)
                            <tr><td class="py-1 pl-6 text-xs fi-color-gray">εκτός: αγορές παγίων {{ $this->fmt($e['capex']) }} (E3_882/883) — δεν αφαιρούνται, εκπίπτουν μέσω αποσβέσεων</td><td></td>@if ($proj)<td></td>@endif</tr>
                        @endif
                        <tr class="font-semibold"><td class="py-1">Κέρδος (λογιστικό)</td><td class="text-right">{{ $this->fmt($e['profit']) }}</td>@if ($proj)<td class="text-right">{{ $this->fmt($proj['profit']) }}</td>@endif</tr>
                        <tr><td class="py-1">Φόρος εισοδήματος ({{ rtrim(rtrim(number_format($p->rate, 2, ',', ''), '0'), ',') }}%)</td><td class="text-right">{{ $this->fmt($e['tax']) }}</td>@if ($proj)<td class="text-right fi-color-gray">{{ $this->fmt($proj['tax']) }}</td>@endif</tr>
                        <tr><td class="py-1">Παρακρατήσεις φόρου στα τιμολόγιά μας</td><td class="text-right">− {{ $this->fmt($e['withheld']) }}</td>@if ($proj)<td class="text-right fi-color-gray">− {{ $this->fmt($proj['withheld']) }}</td>@endif</tr>
                        <tr><td class="py-1">Προκαταβολή φόρου επόμενου έτους ({{ rtrim(rtrim(number_format($p->prepaymentRate, 2, ',', ''), '0'), ',') }}%)</td><td class="text-right">+ {{ $this->fmt($e['prepayment_next']) }}</td>@if ($proj)<td class="text-right fi-color-gray">+ {{ $this->fmt($proj['prepayment_next']) }}</td>@endif</tr>
                        <tr>
                            <td class="py-1">
                                Προκαταβολή που βεβαιώθηκε πέρσι
                                @if ($e['prior_source'] === 'assessed')
                                    <span class="text-xs fi-color-gray">· βεβαιωμένη (Φορολογικό προφίλ)</span>
                                @else
                                    <div class="text-xs text-danger-600 dark:text-danger-400">
                                        Δεν έχει οριστεί: συμπληρώστε τη βεβαιωμένη προκαταβολή {{ $this->year }} από το εκκαθαριστικό της δήλωσης {{ $this->year - 1 }} («Φορολογικό προφίλ»). Μέχρι τότε μετράει 0.
                                        @if ($e['prior_hint'] !== null)<span class="fi-color-gray">Εκτίμηση από τα στοιχεία {{ $this->year - 1 }}: {{ $this->fmt($e['prior_hint']) }}.</span>@endif
                                    </div>
                                @endif
                            </td>
                            <td class="text-right">{{ $e['is_current'] ? '—' : '− '.$this->fmt($e['prior_prepayment']) }}</td>@if ($proj)<td class="text-right fi-color-gray">− {{ $this->fmt($e['prior_prepayment']) }}</td>@endif
                        </tr>
                        <tr class="font-bold border-t-2 border-gray-200 dark:border-white/10">
                            <td class="py-2">{{ ($e['headline_payable'] ?? 0) < 0 ? 'Προς επιστροφή / συμψηφισμό' : 'Υπόλοιπο προς πληρωμή' }}</td>
                            {{-- Running year: the full prior prepayment against a PARTIAL year's tax is meaningless → only the projection column settles. --}}
                            <td class="text-right text-lg">{{ $e['is_current'] ? '—' : $this->fmt($e['payable']) }}</td>
                            @if ($proj)<td class="text-right text-lg">{{ $this->fmt($proj['payable']) }}</td>@endif
                        </tr>
                    </tbody>
                </table>
            </div>
            @if ($e['expense_warning'])
                <div class="text-xs text-danger-600 dark:text-danger-400 mt-2">
                    ⚠ Τα έξοδα του {{ $this->year }} είναι κάτω από το 10% των εσόδων. Ελέγξτε αν έχουν εισαχθεί όλα από το myDATA (Κονσόλα myDATA → Έξοδα). Αλλιώς το κέρδος και ο φόρος εδώ είναι υπερεκτιμημένα.
                </div>
            @endif
            @if ($proj)
                <div class="text-xs fi-color-gray mt-2">
                    Η προβολή αναγάγει γραμμικά το αποτέλεσμα των {{ $proj['elapsed_days'] }} ημερών σε {{ $proj['year_days'] }}. Αν ο λογιστής περνά τη μισθοδοσία ανά τρίμηνο, το αποτέλεσμα μέχρι σήμερα φαίνεται μεγαλύτερο μέχρι να περαστεί το τρίμηνο.
                    Το υπόλοιπο του τρέχοντος έτους βγαίνει μόνο στην προβολή: η περσινή προκαταβολή αφορά όλο το έτος, όχι το κομμάτι μέχρι σήμερα.
                </div>
            @endif
            <div class="text-xs fi-color-gray mt-2">
                Εκτίμηση για προγραμματισμό, όχι δήλωση. Το λογιστικό κέρδος διαφέρει από το φορολογητέο (μη εκπιπτόμενες δαπάνες, απογραφή, προβλέψεις), εκτός αν ο λογιστής περνά τις προσαρμογές 17.4/17.6 στο myDATA. Δεν περιλαμβάνεται το τέλος επιτηδεύματος ούτε ο φόρος μερισμάτων.
            </div>
        </x-filament::section>

        {{-- Expenses by bucket --}}
        <x-filament::section heading="Έξοδα ανά κατηγορία">
            @if (count($e['expense_breakdown']) === 0)
                <div class="fi-color-gray text-sm">Δεν υπάρχουν έξοδα για το {{ $this->year }}.</div>
            @else
                <table class="min-w-full text-sm">
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @foreach ($e['expense_breakdown'] as $b)
                            <tr>
                                <td class="py-1">
                                    {{ $b['label'] }}
                                    <div class="text-xs fi-color-gray">{{ $b['count'] }} εγγρ. · έως {{ \Illuminate\Support\Carbon::parse($b['last_date'])->format('d/m/Y') }}</div>
                                </td>
                                <td class="text-right whitespace-nowrap">{{ $this->fmt($b['net']) }}</td>
                            </tr>
                        @endforeach
                        <tr class="font-semibold"><td class="py-1">Σύνολο</td><td class="text-right">{{ $this->fmt($e['expense_all']) }}</td></tr>
                        @if (abs($e['capex']) > 0.004)
                            <tr><td class="py-1 text-xs fi-color-gray" colspan="2">Περιλαμβάνει αγορές παγίων {{ $this->fmt($e['capex']) }}, που δεν μετράνε στον φόρο της χρήσης (μόνο οι αποσβέσεις τους).</td></tr>
                        @endif
                    </tbody>
                </table>
            @endif
        </x-filament::section>
    </div>

    {{-- VAT per quarter + month --}}
    <x-filament::section heading="ΦΠΑ ανά τρίμηνο και μήνα" class="mb-4">
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="fi-color-gray text-xs">
                        <th class="text-left py-1">Περίοδος</th>
                        <th class="text-right py-1">ΦΠΑ εκροών</th>
                        <th class="text-right py-1">ΦΠΑ εισροών</th>
                        <th class="text-right py-1">Διαφορά</th>
                        <th class="text-right py-1">Μεταφερόμενο πιστωτικό</th>
                        <th class="text-right py-1">Προς απόδοση</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                    @foreach ($v['quarters'] as $row)
                        <tr class="font-semibold bg-gray-50 dark:bg-white/5">
                            <td class="py-1 px-2">{{ $row['q']->label }}</td>
                            <td class="text-right">{{ $this->fmt($row['q']->outputVat) }}</td>
                            <td class="text-right">{{ $this->fmt($row['q']->inputVat) }}</td>
                            <td class="text-right {{ $row['q']->netVat() < 0 ? 'text-success-600 dark:text-success-400' : '' }}">{{ $this->fmt($row['q']->netVat()) }}</td>
                            <td class="text-right fi-color-gray">{{ $row['carried_in'] > 0 ? '− '.$this->fmt($row['carried_in']) : '—' }}</td>
                            <td class="text-right">
                                {{ $this->fmt($row['payable']) }}
                                @if ($row['carry_out'] > 0)<div class="text-xs fi-color-gray">πιστωτικό {{ $this->fmt($row['carry_out']) }} → επόμενο</div>@endif
                            </td>
                        </tr>
                        @foreach ($row['months'] as $m)
                            <tr class="fi-color-gray">
                                <td class="py-1 pl-6">{{ $this->monthName($m) }}</td>
                                <td class="text-right">{{ $this->fmt($m->outputVat) }}</td>
                                <td class="text-right">{{ $this->fmt($m->inputVat) }}</td>
                                <td class="text-right">{{ $this->fmt($m->netVat()) }}</td>
                                <td></td><td></td>
                            </tr>
                        @endforeach
                    @endforeach
                    <tr class="font-bold border-t-2 border-gray-200 dark:border-white/10">
                        <td class="py-2 px-2">Σύνολο {{ $this->year }}</td>
                        <td class="text-right">{{ $this->fmt($v['year']->outputVat) }}</td>
                        <td class="text-right">{{ $this->fmt($v['year']->inputVat) }}</td>
                        <td class="text-right">{{ $this->fmt($v['year']->netVat()) }}</td>
                        <td></td>
                        <td class="text-right">{{ $this->fmt($v['payable_total']) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div class="text-xs fi-color-gray mt-2">Το πιστωτικό υπόλοιπο ενός τριμήνου μεταφέρεται στο επόμενο. Η μεταφορά από το προηγούμενο έτος δεν υπολογίζεται εδώ.</div>
    </x-filament::section>

    {{-- Multi-year --}}
    <x-filament::section heading="Ανά έτος">
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="fi-color-gray text-xs">
                        <th class="text-left py-1">Έτος</th>
                        <th class="text-right py-1">Έσοδα</th>
                        <th class="text-right py-1">Έξοδα</th>
                        <th class="text-right py-1">Κέρδος</th>
                        <th class="text-right py-1">Φόρος</th>
                        <th class="text-right py-1">Παρακρατήσεις</th>
                        <th class="text-right py-1">Προκαταβολή επόμ.</th>
                        <th class="text-right py-1">Υπόλοιπο</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                    @foreach ($this->yearsSummary() as $ys)
                        <tr class="{{ $ys['year'] === $this->year ? 'font-semibold' : '' }}">
                            <td class="py-1">
                                <button type="button" wire:click="$set('year', {{ $ys['year'] }})" class="underline">{{ $ys['year'] }}</button>
                                @if ($ys['is_current'])<span class="text-xs fi-color-gray">(μέχρι σήμερα)</span>@endif
                                @if ($ys['expense_warning'])<span class="text-xs text-danger-600 dark:text-danger-400" title="Έξοδα κάτω από 10% των εσόδων: πιθανώς δεν έχουν εισαχθεί">⚠ ελλιπή έξοδα</span>@endif
                            </td>
                            <td class="text-right">{{ $this->fmt($ys['income_total']) }}</td>
                            <td class="text-right">{{ $this->fmt($ys['expense_total']) }}</td>
                            <td class="text-right">{{ $this->fmt($ys['profit']) }}</td>
                            <td class="text-right">{{ $this->fmt($ys['tax']) }}</td>
                            <td class="text-right">{{ $this->fmt($ys['withheld']) }}</td>
                            <td class="text-right">{{ $this->fmt($ys['prepayment_next']) }}</td>
                            <td class="text-right">{{ $ys['headline_payable'] === null ? '—' : $this->fmt($ys['headline_payable']) }}@if ($ys['is_current'] && $ys['headline_payable'] !== null)<span class="text-xs fi-color-gray"> (προβολή)</span>@endif</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
