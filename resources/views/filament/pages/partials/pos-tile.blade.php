{{-- One product key of the «Ταμείο» grid (search results + «Αγαπημένα»). --}}
<button type="button" wire:key="p-{{ $product->id }}" wire:click="choose({{ $product->id }})" class="pos-item">
    @if ($url = $page->photoUrl($product))
        <img src="{{ $url }}" alt="">
    @endif
    <div class="pos-item-name">{{ $product->description_short }}</div>
    <div class="pos-item-price">
        @if ($product->isVariable())
            Επιλογή παραλλαγής ›
        @elseif ($product->pos_open_price)
            Ελεύθερη τιμή ›
        @else
            {{ number_format($page->unitPrice($product), 2, ',', '.') }} €
        @endif
    </div>
</button>
