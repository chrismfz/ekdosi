@php
    /** @var \App\Models\Note $note */
    $note = $getRecord();
@endphp

<div class="space-y-2">
    {{-- Header: pin + title + kind/source badges --}}
    <div class="flex flex-wrap items-start justify-between gap-2">
        <div class="flex items-start gap-2 min-w-0">
            @if ($note->is_pinned)
                <x-filament::icon icon="heroicon-s-bookmark" class="h-4 w-4 mt-0.5 shrink-0 text-amber-500" />
            @endif
            <span class="text-base font-bold break-all">{{ $note->displayTitle() }}</span>
        </div>
        <div class="flex flex-wrap items-center gap-1 shrink-0">
            <x-filament::badge :color="$note->kind === \App\Models\Note::KIND_TECHNICAL ? 'info' : 'gray'" size="sm">
                {{ $note->kindLabel() }}
            </x-filament::badge>
            @if ($note->sourceLabel())
                <x-filament::badge color="gray" size="sm">{{ $note->sourceLabel() }}</x-filament::badge>
            @endif
        </div>
    </div>

    {{-- Tags --}}
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

    {{-- Body (rendered markdown; clamped — full text via «Άνοιγμα») --}}
    <div class="ekdosi-note-body">
        <div class="ekdosi-md">{!! $note->renderedBody() !!}</div>
    </div>

    {{-- Footer: author + last edit --}}
    <div class="text-xs fi-color-gray">
        {{ $note->author?->name ?? 'Σύστημα' }} · {{ $note->updated_at?->format('d/m/Y H:i') }}
    </div>
</div>
