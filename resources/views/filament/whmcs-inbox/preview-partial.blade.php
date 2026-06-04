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

        {{-- Source row footer --}}
        <div class="text-xs text-gray-500">
            Από WHMCS παραστατικό #{{ $preview->source['whmcs_invoice_id'] }},
            ημερομηνία {{ $preview->source['whmcs_date'] }},
            σύνολο WHMCS: {{ $fmt($preview->source['whmcs_total']) }}.
            @if (abs($preview->source['whmcs_total'] - $preview->totals['gross_total']) > 0.01)
                <span class="text-warning-600 font-semibold">
                    ⚠️ Διαφορά από το ekdosi σύνολο ({{ $fmt(abs($preview->source['whmcs_total'] - $preview->totals['gross_total'])) }}) —
                    έλεγξε τους υπολογισμούς ΦΠΑ.
                </span>
            @endif
        </div>
    </div>
@endif
