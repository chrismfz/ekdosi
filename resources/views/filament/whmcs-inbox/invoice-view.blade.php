{{--
    E: full WHMCS invoice view, rendered from the STAGED payload (no live API
    call — the ingestor already fetched the rich GetInvoice shape with line
    items + the client's custom fields). Opened by clicking the WHMCS # in the
    inbox. Read-only; linking/import to an ekdosi customer is a follow-up.

    Input: $r — the PendingWhmcsInvoice record.
--}}
@php
    $p = $r->payload ?? [];
    $cur = (string) ($p['currencycode'] ?? '');
    $money = fn ($v) => number_format((float) $v, 2, ',', '.').($cur ? ' '.$cur : '');

    // Line items: WHMCS returns items.item as a list, or a single object when
    // there's exactly one line (same quirk handled across the bridge).
    $items = $p['items']['item'] ?? [];
    if ($items && ! array_is_list($items)) {
        $items = [$items];
    }
@endphp

<div class="space-y-4 text-sm">
    {{-- Header --}}
    <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
        <div>
            <div class="text-gray-500 dark:text-gray-400">WHMCS #</div>
            <div class="font-semibold">{{ ($p['invoicenum'] ?? '') ?: ('#'.$r->whmcs_invoice_id) }}</div>
        </div>
        <div>
            <div class="text-gray-500 dark:text-gray-400">Ημερομηνία</div>
            <div class="font-semibold">{{ $p['date'] ?? '—' }}</div>
        </div>
        <div>
            <div class="text-gray-500 dark:text-gray-400">Κατάσταση WHMCS</div>
            <div class="font-semibold">{{ $p['status'] ?? '—' }}</div>
        </div>
        <div>
            <div class="text-gray-500 dark:text-gray-400">Σύνολο</div>
            <div class="font-semibold">{{ $money($p['total'] ?? 0) }}</div>
        </div>
    </div>

    {{-- Client + intent --}}
    <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
        <div class="mb-1 font-medium">Πελάτης (WHMCS)</div>
        <div>{{ $r->whmcsClientName() ?? '—' }}
            @if ($email = ($p['email'] ?? null))
                <span class="text-gray-500 dark:text-gray-400">· {{ $email }}</span>
            @endif
        </div>
        <div class="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-gray-600 dark:text-gray-300">
            <span>ΑΦΜ: <strong>{{ $r->whmcsAfm() ?: '—' }}</strong></span>
            @if ($doy = $r->whmcsTaxOffice()) <span>ΔΟΥ: {{ $doy }}</span> @endif
            @if ($act = $r->whmcsActivity()) <span>Δραστηριότητα: {{ $act }}</span> @endif
            <span>Πρόθεση:
                @switch($r->wantsInvoice())
                    @case(true) <strong>Τιμολόγιο</strong> @break
                    @case(false) <strong>Απόδειξη</strong> @break
                    @default <span class="text-gray-400">άγνωστη</span>
                @endswitch
            </span>
        </div>
        @if ($r->needsAfm())
            <div class="mt-1 text-danger-600 dark:text-danger-400">⚠ Ζήτησε τιμολόγιο αλλά λείπει ΑΦΜ.</div>
        @endif
    </div>

    {{-- Line items --}}
    <div>
        <div class="mb-1 font-medium">Γραμμές</div>
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="text-left text-gray-500 dark:text-gray-400">
                    <tr class="border-b border-gray-200 dark:border-white/10">
                        <th class="py-1 pr-4">Περιγραφή</th>
                        <th class="py-1 text-right">Ποσό</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($items as $it)
                        <tr class="border-b border-gray-100 dark:border-white/5">
                            <td class="py-1 pr-4">{{ $it['description'] ?? '—' }}</td>
                            <td class="py-1 text-right whitespace-nowrap">{{ $money($it['amount'] ?? 0) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="2" class="py-1 text-gray-400">Καμία γραμμή στο payload.</td></tr>
                    @endforelse
                </tbody>
                <tfoot class="font-semibold">
                    @if (isset($p['subtotal']))
                        <tr><td class="py-1 pr-4 text-right text-gray-500 dark:text-gray-400">Υποσύνολο</td><td class="py-1 text-right">{{ $money($p['subtotal']) }}</td></tr>
                    @endif
                    @if (isset($p['tax']) && (float) $p['tax'] != 0.0)
                        <tr><td class="py-1 pr-4 text-right text-gray-500 dark:text-gray-400">ΦΠΑ</td><td class="py-1 text-right">{{ $money($p['tax']) }}</td></tr>
                    @endif
                    <tr class="border-t border-gray-200 dark:border-white/10"><td class="py-1 pr-4 text-right">Σύνολο</td><td class="py-1 text-right">{{ $money($p['total'] ?? 0) }}</td></tr>
                </tfoot>
            </table>
        </div>
    </div>

    {{-- ekdosi mapping --}}
    <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
        <div class="mb-1 font-medium">Αντιστοίχιση στο ekdosi</div>
        <div>Πελάτης:
            @if ($r->customer)
                <strong>{{ $r->customer->name }}</strong>
                @if ($r->customer->afm) <span class="text-gray-500 dark:text-gray-400">(ΑΦΜ {{ $r->customer->afm }})</span> @endif
                <span class="text-gray-400">· match: {{ $r->match_reason }}</span>
            @else
                <span class="text-warning-600 dark:text-warning-400">— μη συνδεδεμένος —</span>
            @endif
        </div>
        @if ($r->invoice)
            <div class="mt-1">Παραστατικό: <strong>{{ $r->invoice->invcode }}</strong>
                @if ($r->mydata_mark) · ΜΑΡΚ <span class="font-mono text-xs">{{ $r->mydata_mark }}</span> @endif
            </div>
        @endif
        <div class="mt-1 text-xs text-gray-400">
            Η σύνδεση/import σε πελάτη ekdosi έπεται. Προς το παρόν: «Καταχώρηση στην ΑΑΔΕ» επιλέγει/επιβεβαιώνει τον πελάτη.
        </div>
    </div>
</div>
