{{-- One product key of the «Ταμείο» grid (search results + «Αγαπημένα»). --}}
<button type="button" wire:key="p-{{ $product->id }}" wire:click="choose({{ $product->id }})" class="pos-item">
    @if ($url = $page->photoUrl($product))
        <img src="{{ $url }}" alt="">
    @endif
    <div class="pos-item-name">{{ $product->description_short }}</div>
    <div class="pos-item-price">
        @if ($product->isVariable())
            Επιλογή παραλλαγής ›
        @elseif (\App\Actions\CreatePosSale::vatOf($product) === null)
            ⚠ χωρίς ενεργό ΦΠΑ
        @elseif ($product->isOpenPrice())
            Ελεύθερη τιμή ›
        @else
            {{ number_format($page->unitPrice($product), 2, ',', '.') }} €@if (($levy = $page->unitLevy($product)) != 0) <small>{{ $levy > 0 ? '+' : '−' }} τέλος {{ number_format(abs($levy), 2, ',', '.') }}</small>@endif
        @endif
    </div>
</button>
