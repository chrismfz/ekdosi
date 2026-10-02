<x-filament-panels::page>
    <style>
        .pos { display: grid; grid-template-columns: minmax(0, 3fr) minmax(320px, 2fr); gap: 1rem; align-items: start; }
        @media (max-width: 900px) { .pos { grid-template-columns: 1fr; } }
        .pos-card { background: #fff; border: 1px solid #e5e7eb; border-radius: .75rem; padding: 1rem; }
        .dark .pos-card { background: #111827; border-color: #374151; }
        .pos-scan { width: 100%; font-size: 1.25rem; padding: .75rem 1rem; border: 2px solid #f59e0b; border-radius: .5rem; background: transparent; color: inherit; }
        .pos-search { width: 100%; margin-top: .75rem; padding: .5rem .75rem; border: 1px solid #d1d5db; border-radius: .5rem; background: transparent; color: inherit; }
        .dark .pos-search { border-color: #4b5563; }
        .pos-hint { font-size: .8rem; color: #6b7280; margin-top: .35rem; }
        .pos-results { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: .6rem; margin-top: .9rem; }
        .pos-item { text-align: left; border: 1px solid #e5e7eb; border-radius: .6rem; padding: .5rem; cursor: pointer; background: transparent; color: inherit; }
        .pos-item:hover { border-color: #f59e0b; }
        .dark .pos-item { border-color: #374151; }
        .pos-item img { width: 100%; aspect-ratio: 1; object-fit: cover; border-radius: .4rem; background: #f3f4f6; }
        .pos-item-name { font-size: .85rem; font-weight: 600; margin-top: .35rem; line-height: 1.2; }
        .pos-item-price { font-size: .85rem; color: #b45309; font-weight: 700; }
        .pos-picker-head { display: flex; justify-content: space-between; align-items: center; margin-top: 1rem; font-weight: 600; }
        .pos-variants { display: grid; grid-template-columns: repeat(auto-fill, minmax(120px, 1fr)); gap: .5rem; margin-top: .6rem; }
        .pos-variant { border: 1px solid #d1d5db; border-radius: .5rem; padding: .5rem; cursor: pointer; background: transparent; color: inherit; text-align: center; }
        .pos-variant:hover { border-color: #f59e0b; }
        .pos-variant.is-out { opacity: .55; }
        .pos-variant small { display: block; color: #6b7280; }
        .pos-cart-row { display: grid; grid-template-columns: 1fr auto; gap: .3rem; padding: .55rem 0; border-bottom: 1px solid #f3f4f6; }
        .dark .pos-cart-row { border-color: #1f2937; }
        .pos-cart-label { font-weight: 600; font-size: .9rem; }
        .pos-cart-ctl { display: flex; align-items: center; gap: .35rem; font-size: .85rem; color: #6b7280; }
        .pos-cart-ctl button { border: 1px solid #d1d5db; border-radius: .35rem; width: 1.8rem; height: 1.8rem; background: transparent; color: inherit; cursor: pointer; }
        .pos-cart-ctl input { width: 4.2rem; padding: .2rem .35rem; border: 1px solid #d1d5db; border-radius: .35rem; background: transparent; color: inherit; }
        .pos-cart-gross { font-weight: 700; text-align: right; }
        .pos-total { display: flex; justify-content: space-between; font-size: 1.6rem; font-weight: 800; margin-top: 1rem; }
        .pos-pay { display: grid; grid-template-columns: 1fr 1fr; gap: .5rem; margin-top: .75rem; align-items: center; }
        .pos-pay input { padding: .5rem .75rem; font-size: 1.1rem; border: 1px solid #d1d5db; border-radius: .5rem; background: transparent; color: inherit; }
        .pos-change { font-size: 1.1rem; font-weight: 700; color: #15803d; text-align: right; }
        .pos-actions { display: grid; grid-template-columns: 2fr 1fr; gap: .5rem; margin-top: 1rem; }
        .pos-btn { padding: .9rem; border-radius: .6rem; font-weight: 700; font-size: 1.05rem; cursor: pointer; border: 0; }
        .pos-btn-issue { background: #f59e0b; color: #111827; }
        .pos-btn-issue[disabled] { opacity: .5; cursor: wait; }
        .pos-btn-ghost { background: transparent; border: 1px solid #d1d5db; color: inherit; }
        .pos-empty { color: #9ca3af; text-align: center; padding: 1.5rem 0; }
        .pos-btn-sm { padding: .35rem .7rem; font-size: .85rem; }
        .pos-btn-xs { padding: .2rem .5rem; font-size: .75rem; margin-top: .3rem; }
        .pos-btn-block { width: 100%; margin-top: .5rem; font-size: .9rem; }
    </style>

    {{--
        The receipt window is opened SYNCHRONOUSLY on the click (a popup opened after
        the filing round-trip — seconds later — is blocked by the browser) and only
        pointed at the receipt when the sale is issued; closed again on a failure.
        The success notification also carries an «Εκτύπωση απόδειξης» link as a fallback.
        Any key pressed outside a text field goes back to the scan field, so a scanner
        "typing" after a click on +/− never lands on a button.
    --}}
    <div
        class="pos"
        x-data="{
            win: null,
            openWin() { this.win = window.open('', 'pos-receipt', 'width=420,height=720'); },
            print(url) {
                if (this.win && ! this.win.closed) { this.win.location = url; }
                else { this.win = window.open(url, 'pos-receipt', 'width=420,height=720'); }
            },
            cancel() { if (this.win && ! this.win.closed) { this.win.close(); } this.win = null; },
        }"
        x-init="$nextTick(() => $refs.scan?.focus())"
        x-on:pos-focus.window="$nextTick(() => $refs.scan?.focus())"
        x-on:pos-price-focus.window="$nextTick(() => $refs.price?.focus())"
        x-on:pos-print.window="print($event.detail.url)"
        x-on:pos-print-cancel.window="cancel()"
        x-on:keydown.window="if (! $event.target.closest('input, textarea, select, [contenteditable]') && $event.key.length === 1 && $event.key !== ' ' && ! $event.ctrlKey && ! $event.metaKey && ! $event.altKey) { $refs.scan?.focus(); }"
    >
        {{-- Left: scan + search + variant picker --}}
        <div class="pos-card">
            <input
                x-ref="scan"
                class="pos-scan"
                type="text"
                inputmode="none"
                autocomplete="off"
                placeholder="Σκανάρισε barcode ή πληκτρολόγησε κωδικό + Enter"
                x-on:keydown.enter.prevent="const code = $el.value.trim(); $el.value = ''; if (code !== '') { $wire.scanCode(code); }"
            >
            <input class="pos-search" type="search" placeholder="Αναζήτηση με όνομα…" wire:model.live.debounce.300ms="search">
            <div class="pos-hint">Το scanner λειτουργεί σαν πληκτρολόγιο — κράτα το πάνω πεδίο ενεργό.</div>

            @if ($this->pricePromptProduct)
                <div class="pos-picker-head">
                    <span>{{ $this->pricePromptProduct->description_short }} — τιμή με ΦΠΑ</span>
                    <button type="button" class="pos-btn-ghost pos-btn pos-btn-sm" wire:click="closePrice">Ακύρωση</button>
                </div>
                <div class="pos-pay">
                    <input x-ref="price" type="text" inputmode="decimal" placeholder="π.χ. 24,90" wire:model="promptPrice" wire:keydown.enter.prevent="addOpenPrice">
                    <button type="button" class="pos-btn pos-btn-issue" wire:click="addOpenPrice">Προσθήκη</button>
                </div>
            @elseif ($this->pickerParent)
                <div class="pos-picker-head">
                    <span>{{ $this->pickerParent->description_short }} — διάλεξε παραλλαγή</span>
                    <button type="button" class="pos-btn-ghost pos-btn pos-btn-sm" wire:click="closePicker">Κλείσιμο</button>
                </div>
                <div class="pos-variants">
                    @forelse ($this->pickerVariants as $variant)
                        @php($stock = $variant->track_stock ? (float) ($variant->stock_on_hand ?? 0) : null)
                        <button type="button" wire:key="v-{{ $variant->id }}" wire:click="choose({{ $variant->id }})" class="pos-variant {{ $stock !== null && $stock <= 0 ? 'is-out' : '' }}">
                            {{ $variant->orderedVariantValues()->pluck('value')->implode(' / ') }}
                            <small>{{ number_format($this->unitPrice($variant), 2, ',', '.') }} €@if ($stock !== null) · απόθ. {{ rtrim(rtrim(number_format($stock, 3, '.', ''), '0'), '.') }}@endif</small>
                        </button>
                    @empty
                        <div class="pos-empty">Δεν υπάρχουν ενεργές παραλλαγές.</div>
                    @endforelse
                </div>
            @elseif ($this->searchResults->isNotEmpty())
                <div class="pos-results">
                    @foreach ($this->searchResults as $product)
                        @include('filament.pages.partials.pos-tile', ['product' => $product, 'page' => $this])
                    @endforeach
                </div>
            @elseif (mb_strlen(trim($search)) >= 2)
                <div class="pos-empty">Κανένα είδος για «{{ $search }}».</div>
            @elseif ($this->favorites->isNotEmpty())
                <div class="pos-picker-head"><span>Αγαπημένα</span></div>
                <div class="pos-results">
                    @foreach ($this->favorites as $product)
                        @include('filament.pages.partials.pos-tile', ['product' => $product, 'page' => $this])
                    @endforeach
                </div>
            @endif
        </div>

        {{-- Right: cart + pay --}}
        <div class="pos-card">
            @forelse ($this->cartView as $i => $line)
                <div class="pos-cart-row" wire:key="c-{{ $i }}">
                    <div>
                        <div class="pos-cart-label">{{ $line['label'] }}</div>
                        <div class="pos-cart-ctl">
                            <button type="button" wire:click="decrement({{ $i }})" aria-label="Λιγότερα">−</button>
                            <input type="number" min="0.001" step="1" wire:model.blur="cart.{{ $i }}.qty" aria-label="Ποσότητα">
                            <button type="button" wire:click="increment({{ $i }})" aria-label="Περισσότερα">+</button>
                            × {{ number_format($line['unit'], 2, ',', '.') }} €
                            · έκπτ. <input type="number" min="0" max="100" step="1" wire:model.blur="cart.{{ $i }}.discount" aria-label="Έκπτωση %">%
                        </div>
                    </div>
                    <div>
                        <div class="pos-cart-gross">{{ number_format($line['gross'], 2, ',', '.') }} €</div>
                        <button type="button" class="pos-btn-ghost pos-btn pos-btn-xs" wire:click="remove({{ $i }})">Αφαίρεση</button>
                    </div>
                </div>
            @empty
                <div class="pos-empty">Το καλάθι είναι άδειο — σκανάρισε ένα είδος.</div>
            @endforelse

            <div class="pos-total"><span>Σύνολο</span><span>{{ number_format($this->total, 2, ',', '.') }} €</span></div>

            <div class="pos-pay">
                <input type="text" inputmode="decimal" placeholder="Πήρα (μετρητά)" wire:model.live.debounce.200ms="tendered">
                <div class="pos-change">
                    @if (($change = $this->change()) !== null)
                        Ρέστα: {{ number_format($change, 2, ',', '.') }} €
                    @endif
                </div>
            </div>

            <div class="pos-actions">
                <button type="button" class="pos-btn pos-btn-issue" x-on:click="openWin()" wire:click="checkout" wire:loading.attr="disabled" wire:target="checkout" @disabled($cart === [])>
                    <span wire:loading.remove wire:target="checkout">Έκδοση απόδειξης (μετρητά)</span>
                    <span wire:loading wire:target="checkout">Έκδοση…</span>
                </button>
                <button type="button" class="pos-btn pos-btn-ghost" wire:click="clearCart" wire:confirm="Άδειασμα καλαθιού;">Άδειασμα</button>
            </div>

            @if ($lastInvoiceId)
                <button type="button" class="pos-btn pos-btn-ghost pos-btn-block" x-on:click="openWin()" wire:click="reprint">Επανεκτύπωση τελευταίας απόδειξης</button>
            @endif
        </div>
    </div>
</x-filament-panels::page>
