{{-- Stage B-2: full-preview partial for the "File at AADE" modal.
     Rendered reactively when the operator changes customer / invoice
     type in the form. Receives a FilePreview value object OR a string
     error message. --}}

@php /** @var \App\Services\WhmcsInbox\FilePreview|string $preview */ @endphp

@if (is_string($preview))
    <div class="rounded-lg bg-warning-50 dark:bg-warning-950/40 p-3 text-sm text-warning-700 dark:text-warning-300">
        {{ $preview }}
    </div>
@elseif (! ($preview instanceof \App\Services\WhmcsInbox\FilePreview))
    <div class="text-sm text-gray-500">Δεν είναι δυνατή η προεπισκόπηση.</div>
@else
    @php
        $fmt = fn ($v) => number_format((float) $v, 2, ',', '.').' €';
    @endphp

    <div class="space-y-4">
        {{-- Customer snapshot --}}
        <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-3">
            <div class="text-xs uppercase text-gray-500 mb-1">Στοιχεία πελάτη (snapshot)</div>
            <div class="font-semibold">{{ $preview->header['company_name'] }}</div>
            <div class="text-sm text-gray-600 dark:text-gray-400">
                @if ($preview->header['vat_no']) ΑΦΜ: <strong>{{ $preview->header['vat_no'] }}</strong> @endif
                @if ($preview->header['occupation']) · {{ $preview->header['occupation'] }} @endif
            </div>
            <div class="text-sm text-gray-600 dark:text-gray-400">
                {{ $preview->header['address1'] }}{{ $preview->header['city'] ? ', '.$preview->header['city'] : '' }}{{ $preview->header['postcode'] ? ' '.$preview->header['postcode'] : '' }}
            </div>
        </div>

        {{-- Lines table --}}
        <div class="rounded-lg border border-gray-200 dark:border-gray-700 overflow-hidden">
            <div class="bg-gray-50 dark:bg-gray-900 px-3 py-2 text-xs uppercase text-gray-500 flex justify-between">
                <span>Γραμμές παραστατικού</span>
                <span>Σειρά: <strong>{{ $preview->invoiceType->code }}</strong></span>
            </div>
            <table class="min-w-full text-sm">
                <thead class="text-xs text-gray-500 uppercase border-b border-gray-200 dark:border-gray-700">
                    <tr>
                        <th class="px-3 py-2 text-left">Περιγραφή</th>
                        <th class="px-3 py-2 text-right">Ποσότ.</th>
                        <th class="px-3 py-2 text-right">Τιμή μον.</th>
                        <th class="px-3 py-2 text-right">ΦΠΑ%</th>
                        <th class="px-3 py-2 text-right">Καθαρή</th>
                        <th class="px-3 py-2 text-right">Με ΦΠΑ</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                    @foreach ($preview->lines as $line)
                        <tr>
                            <td class="px-3 py-2">{{ $line['product_descr'] ?? '' }}</td>
                            <td class="px-3 py-2 text-right">{{ number_format((float) $line['qty'], 3, ',', '.') }}</td>
                            <td class="px-3 py-2 text-right font-mono">{{ $fmt($line['price_per_item']) }}</td>
                            <td class="px-3 py-2 text-right">{{ number_format((float) $line['vat_percent'], 2, ',', '.') }}%</td>
                            <td class="px-3 py-2 text-right font-mono">{{ $fmt($line['net_price']) }}</td>
                            <td class="px-3 py-2 text-right font-mono">{{ $fmt($line['gross_price']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{-- VAT breakdown + totals --}}
        <div class="grid grid-cols-2 gap-3">
            <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-3 text-sm">
                <div class="text-xs uppercase text-gray-500 mb-1">Ανάλυση ΦΠΑ</div>
                @foreach ($preview->totals['vat_breakdown'] as $b)
                    <div class="flex justify-between">
                        <span>ΦΠΑ {{ number_format((float) $b['rate'], 2, ',', '.') }}%</span>
                        <span class="font-mono">{{ $fmt($b['net']) }} → {{ $fmt($b['vat']) }} → {{ $fmt($b['gross']) }}</span>
                    </div>
                @endforeach
            </div>
            <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-3 text-sm">
                <div class="text-xs uppercase text-gray-500 mb-1">Σύνολα</div>
                <div class="flex justify-between">
                    <span>Καθαρή αξία</span>
                    <span class="font-mono">{{ $fmt($preview->totals['net_total']) }}</span>
                </div>
                <div class="flex justify-between">
                    <span>Σύνολο ΦΠΑ</span>
                    <span class="font-mono">{{ $fmt($preview->totals['vat_total']) }}</span>
                </div>
                <div class="flex justify-between font-semibold text-base mt-1 pt-1 border-t border-gray-200 dark:border-gray-700">
                    <span>Συνολική αξία</span>
                    <span class="font-mono">{{ $fmt($preview->totals['gross_total']) }}</span>
                </div>
            </div>
        </div>

        {{-- Source row footer — reconciles against the WHMCS DOCUMENT value. A
             credit-paid WHMCS invoice reports total = subtotal + tax − credit (0 when
             paid fully from credit), so comparing against `total` alone flagged a
             false ⚠ on every one of them; the VAT is compared too (the field that
             exposes a net/gross mix-up). Mirrors WhmcsFilingGuard::assertTotalsReconcile. --}}
        @php
            $src = $preview->source;
            $wTotal = (float) ($src['whmcs_total'] ?? 0);
            $wCredit = isset($src['whmcs_credit']) ? (float) $src['whmcs_credit'] : null;
            $wSubtotal = (float) ($src['whmcs_subtotal'] ?? 0);
            $wTax = (float) ($src['whmcs_tax'] ?? 0);
            $tol = 0.02 + 0.01 * count($preview->lines);
            $gross = (float) $preview->totals['gross_total'];
            $documentValue = $wCredit !== null ? $wTotal + $wCredit : $wTotal;
            $candidates = array_filter(
                $wCredit !== null
                    ? [$documentValue]
                    : [$wTotal, $wSubtotal > 0.005 ? $wSubtotal + $wTax : null, $wSubtotal > 0.005 ? $wSubtotal : null],
                fn ($v) => $v !== null && $v > 0.005,
            );
            $grossMismatch = $candidates !== [] && collect($candidates)->every(fn ($v) => abs($v - $gross) > $tol);
            $vat = (float) $preview->totals['vat_total'];
            // Also when WHMCS reported 0 VAT (exempt client) but we'd file some.
            // Same thresholds as WhmcsFilingGuard: WHMCS reported 0 VAT but we'd file
            // any → warn (the auto path holds at > 0.005); otherwise beyond tolerance.
            $vatMismatch = ! empty($src['whmcs_tax_reported']) && $wTax <= 0.005
                ? $vat > 0.005
                : $wTax > 0.005 && abs($wTax - $vat) > $tol;
            // The method the document is issued with: a cash-term one (due_days 0) is settled at issue, with no payment;
            // a deleted one too (the invoice would not find it).
            $method = ($preview->header['payment_method_id'] ?? null) !== null
                ? \App\Models\PaymentMethod::withoutGlobalScopes()->whereNull('deleted_at')->find($preview->header['payment_method_id'])
                : null;
            $creditTerm = (int) ($method?->due_days ?? 0) > 0;
        @endphp
        <div class="text-sm">
            Τρόπος πληρωμής: <strong>{{ $method?->description ?? '—' }}</strong>
            <span class="text-gray-500">({{ $creditTerm ? 'επί πιστώσει: ανοιχτή οφειλή μέχρι την πληρωμή' : 'εξοφλείται με την έκδοση' }})</span>
            @if (! empty($src['whmcs_unpaid']) && ! $creditTerm)
                <div class="text-danger-600 font-semibold">
                    ⚠️ Απλήρωτο, αλλά δεν υπάρχει τρόπος πληρωμής «Επί Πιστώσει» (τύπος myDATA 5, με ημέρες πίστωσης):
                    θα εκδοθεί εξοφλημένο. Όρισέ τον στους Τρόπους πληρωμής ή άλλαξέ τον στο προσχέδιο.
                </div>
            @endif
            @if (! empty($src['whmcs_unpaid']) && (float) ($src['whmcs_paid_before_issue'] ?? 0) > 0.005)
                <div class="text-warning-600 font-semibold">
                    ⚠️ Έχει ήδη πληρωθεί {{ $fmt($src['whmcs_paid_before_issue']) }} στην πηγή (πίστωση ή μερική πληρωμή), που
                    δεν καταγράφεται αυτόματα: μετά την έκδοση, καταχώρησέ το στις Πληρωμές,
                    {{ $creditTerm ? 'αλλιώς θα φαίνεται οφειλή όλο το ποσό.' : 'ώστε το υπόλοιπο να μείνει ανοιχτή οφειλή (αλλιώς φαίνεται εξοφλημένο).' }}
                </div>
            @endif
        </div>
        <div class="text-xs text-gray-500 space-y-1">
            <div>
                Από WHMCS παραστατικό #{{ $src['whmcs_invoice_id'] }},
                ημερομηνία {{ $src['whmcs_date'] }},
                αξία WHMCS: {{ $fmt($documentValue) }}@if ($wTax > 0.005) (ΦΠΑ {{ $fmt($wTax) }})@endif
                @if ($wCredit !== null && $wCredit > 0.005)
                    — εξοφλήθηκε {{ $fmt($wCredit) }} από πίστωση, υπόλοιπο {{ $fmt($wTotal) }}
                @endif.
            </div>
            @if (! empty($preview->totals['taxtype_conflict']))
                <div class="text-warning-600 font-semibold">
                    ⚠️ Το WHMCS δηλώνει ΦΠΑ «{{ $src['whmcs_taxtype'] ?? '' }}» αλλά τα ποσά αυτού του τιμολογίου
                    αντιστοιχούν στο αντίθετο (καθαρά/μικτά) — έλεγξε τα ποσά πριν την έκδοση.
                </div>
            @endif
            @if ($vatMismatch)
                <div class="text-warning-600 font-semibold">
                    ⚠️ ΦΠΑ ekdosi {{ $fmt($vat) }} ≠ ΦΠΑ WHMCS {{ $fmt($wTax) }} —
                    @if ($wTax <= 0.005)
                        το WHMCS δεν χρέωσε ΦΠΑ (απαλλαγμένος πελάτης;): βάλε 0% με αιτία απαλλαγής.
                    @else
                        πιθανή σύγχυση καθαρών/μικτών ή άλλος συντελεστής.
                    @endif
                </div>
            @endif
            @if ($grossMismatch)
                <div class="text-warning-600 font-semibold">
                    ⚠️ Διαφορά από το ekdosi σύνολο ({{ $fmt(abs($documentValue - $gross)) }}) —
                    έλεγξε τους υπολογισμούς ΦΠΑ.
                </div>
            @endif
        </div>
    </div>
@endif
