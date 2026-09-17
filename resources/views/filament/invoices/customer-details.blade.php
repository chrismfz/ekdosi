{{--
    The customer snapshot fields that were moved OFF the compact Customer card into
    the «Πλήρη στοιχεία» modal. Name + ΑΦΜ stay inline on the card, so they are NOT
    repeated here (avoids two sources for the same value). These are the values
    FROZEN at issue time (invoice snapshot columns), not the live customer record.

    Utility classes mirror the WHMCS-inbox modal (invoice-view.blade.php); the panel
    ships no Tailwind utility layer of its own, but these exact classes are already
    covered for the panel (resources/css/panel.css), so reusing them keeps this styled.

    Input: $invoice — the Invoice record.
--}}
@php
    $branch = $invoice->filedCounterpartBranch();
    $addr = trim(($invoice->address1 ?? '').' '.($invoice->address2 ?? ''));
@endphp

<div class="space-y-4 text-sm">
    <div class="grid grid-cols-2 gap-3">
        <div>
            <div class="text-gray-500 dark:text-gray-400">ΦΠΑ VIES</div>
            <div class="font-semibold">{{ $invoice->vies_vat ?: '—' }}</div>
        </div>
        <div>
            <div class="text-gray-500 dark:text-gray-400">Δραστηριότητα</div>
            <div class="font-semibold">{{ $invoice->occupation ?: '—' }}</div>
        </div>
    </div>

    <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
        <div class="mb-1 font-medium">Διεύθυνση</div>
        <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
            <div class="md:col-span-2">
                <div class="text-gray-500 dark:text-gray-400">Οδός</div>
                <div>{{ $addr !== '' ? $addr : '—' }}</div>
            </div>
            <div>
                <div class="text-gray-500 dark:text-gray-400">Πόλη</div>
                <div>{{ $invoice->city ?: '—' }}</div>
            </div>
            <div>
                <div class="text-gray-500 dark:text-gray-400">Τ.Κ.</div>
                <div>{{ $invoice->postcode ?: '—' }}</div>
            </div>
            <div>
                <div class="text-gray-500 dark:text-gray-400">Χώρα</div>
                <div>{{ $invoice->country ?: '—' }}</div>
            </div>
            @if ($branch > 0)
                <div>
                    <div class="text-gray-500 dark:text-gray-400">Εγκατάσταση πελάτη (myDATA)</div>
                    <div>{{ $branch }}</div>
                </div>
            @endif
        </div>
    </div>

    <div class="text-xs text-gray-400">
        Στιγμιότυπο κατά την έκδοση — νομικά παγωμένα, δεν αντικατοπτρίζουν μεταγενέστερες αλλαγές του πελάτη.
    </div>
</div>
