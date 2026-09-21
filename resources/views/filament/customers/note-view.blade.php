@php
    /** @var \App\Models\Note $note */
@endphp

<div class="space-y-3">
    <div class="flex flex-wrap items-center gap-1">
        @if ($note->is_pinned)
            <x-filament::badge color="warning" size="sm" icon="heroicon-s-bookmark">Καρφιτσωμένη</x-filament::badge>
        @endif
        <x-filament::badge :color="$note->kind === \App\Models\Note::KIND_TECHNICAL ? 'info' : 'gray'" size="sm">
            {{ $note->kindLabel() }}
        </x-filament::badge>
        @if ($note->sourceLabel())
            <x-filament::badge color="gray" size="sm">{{ $note->sourceLabel() }}</x-filament::badge>
        @endif
    </div>

    @if ($note->tags->isNotEmpty())
        <div class="flex flex-wrap gap-1">
            @foreach ($note->tags as $tag)
                @php($hex = preg_match('/^#[0-9a-fA-F]{6}$/', (string) $tag->color) ? $tag->color : null)
                <span class="rounded-full px-2 py-0.5 text-xs bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300"
                    @if ($hex) style="background-color: {{ $hex }}1a; color: {{ $hex }};" @endif>
                    {{ $tag->name }}
                </span>
            @endforeach
        </div>
    @endif

    <div class="ekdosi-md">{!! $note->renderedBody() !!}</div>

    <div class="text-xs fi-color-gray border-t border-gray-200 dark:border-white/10 pt-2">
        {{ $note->author?->name ?? 'Σύστημα' }} · δημιουργήθηκε {{ $note->created_at?->format('d/m/Y H:i') }}
        @if ($note->updated_at && $note->created_at && $note->updated_at->ne($note->created_at))
            · επεξεργάστηκε {{ $note->updated_at->format('d/m/Y H:i') }}
        @endif
    </div>
</div>
