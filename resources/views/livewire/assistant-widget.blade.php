<div class="ai-widget">
    {{-- Floating toggle button --}}
    <button type="button" wire:click="$toggle('open')" class="ai-widget-fab" title="Βοηθός AI">
        <x-filament::icon :icon="$open ? 'heroicon-o-x-mark' : 'heroicon-o-sparkles'" class="h-6 w-6" />
    </button>

    {{-- Chat panel --}}
    @if ($open)
        <div class="ai-widget-panel">
            <div class="flex items-center justify-between border-b border-gray-200 p-3 dark:border-white/10">
                <span class="text-sm font-semibold">Βοηθός AI</span>
                @if (count($transcript) > 0)
                    <button type="button" wire:click="clearChat" class="text-xs text-gray-400 hover:underline dark:text-gray-500">Καθαρισμός</button>
                @endif
            </div>

            <div class="ai-widget-log flex flex-col gap-2 p-3">
                @forelse ($transcript as $turn)
                    <div @class([
                        'rounded-lg p-2 text-sm',
                        'bg-gray-100 dark:bg-gray-800 self-end' => $turn['role'] === 'user',
                        'bg-primary-50 dark:bg-primary-400/10' => $turn['role'] === 'assistant',
                        'bg-warning-50 text-warning-700 dark:bg-warning-950/40 dark:text-warning-400' => $turn['role'] === 'system',
                    ]) style="max-width: 90%; white-space: pre-line;">{!! $turn['role'] === 'assistant' ? \App\Support\Assistant\ChatMarkup::render($turn['text']) : e($turn['text']) !!}</div>
                @empty
                    <div class="text-sm text-gray-400 dark:text-gray-500">Ρωτήστε για τα δεδομένα της εταιρείας…</div>
                @endforelse
                <div wire:loading wire:target="send" class="text-xs text-gray-400 dark:text-gray-500">Σκέφτομαι…</div>
            </div>

            @foreach ($this->pendingAssistantActions() as $action)
                <div class="ai-confirm-card" wire:key="ai-w-action-{{ $action['id'] }}">
                    <div class="text-sm">{{ $action['summary'] }}</div>
                    <div class="flex items-center gap-2">
                        <x-filament::button size="sm" color="success" icon="heroicon-o-check"
                            wire:click="confirmAssistantAction({{ $action['id'] }})"
                            wire:loading.attr="disabled">Επιβεβαίωση</x-filament::button>
                        <x-filament::button size="sm" color="gray" icon="heroicon-o-x-mark"
                            wire:click="cancelAssistantAction({{ $action['id'] }})"
                            wire:loading.attr="disabled">Άκυρο</x-filament::button>
                    </div>
                </div>
            @endforeach

            <form wire:submit="send" class="flex items-center gap-2 border-t border-gray-200 p-2 dark:border-white/10">
                <x-filament::input.wrapper class="flex-1">
                    <x-filament::input type="text" wire:model="draft" placeholder="Ερώτηση…" wire:loading.attr="disabled" />
                </x-filament::input.wrapper>
                <x-filament::button type="submit" size="sm" wire:loading.attr="disabled" wire:target="send" icon="heroicon-o-paper-airplane" />
            </form>
        </div>
    @endif
</div>
