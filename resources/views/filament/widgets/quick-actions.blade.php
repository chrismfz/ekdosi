<x-filament-widgets::widget>
    @php($items = $this->actions())
    @if (filled($items))
        <x-filament::section>
            <x-slot name="heading">Καθημερινές εργασίες</x-slot>

            {{-- Flexbox (not grid): every utility here is already in panel.css, and
                 the inline min-width lets the buttons wrap 2–4 per row responsively
                 without needing a grid-cols-* class the no-build CSS lacks. --}}
            <div class="flex flex-wrap gap-3">
                @foreach ($items as $item)
                    <div class="flex-1" style="min-width: 12rem;">
                        <x-filament::button
                            tag="a"
                            :href="$item['url']"
                            :icon="$item['icon']"
                            :color="$item['color']"
                            :badge="$item['badge']"
                            class="w-full"
                        >
                            {{ $item['label'] }}
                        </x-filament::button>
                    </div>
                @endforeach
            </div>
        </x-filament::section>
    @endif
</x-filament-widgets::widget>
