<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">Βοηθός AI</x-slot>
        <x-slot name="description">
            Ρωτήστε για τα δεδομένα της τρέχουσας εταιρείας (π.χ. «πόσες πωλήσεις τον μήνα», «πόσα μας χρωστάνε»).
            Διαβάζει μόνο — δεν αλλάζει τίποτα. Βλέπει μόνο αυτή την εταιρεία.
        </x-slot>

        <div class="flex flex-col gap-3">
            <div class="flex flex-col gap-2" style="min-height: 16rem; max-height: 28rem; overflow-y: auto;">
                @forelse ($transcript as $turn)
                    <div @class([
                        'rounded-lg p-3 text-sm',
                        'bg-gray-100 dark:bg-gray-800 self-end' => $turn['role'] === 'user',
                        'bg-primary-50 dark:bg-primary-400/10' => $turn['role'] === 'assistant',
                        'bg-warning-50 text-warning-700 dark:bg-warning-950/40 dark:text-warning-400' => $turn['role'] === 'system',
                    ]) style="max-width: 85%; white-space: pre-line;">{!! $turn['role'] === 'assistant' ? \App\Support\Assistant\ChatMarkup::render($turn['text']) : e($turn['text']) !!}</div>
                @empty
                    <div class="text-sm text-gray-400 dark:text-gray-500">Ξεκινήστε μια ερώτηση…</div>
                @endforelse
            </div>

            @foreach ($this->pendingAssistantActions() as $action)
                <div class="ai-confirm-card" wire:key="ai-action-{{ $action['id'] }}">
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

            <form wire:submit="send" class="flex items-center gap-2">
                <x-filament::input.wrapper class="flex-1">
                    <x-filament::input
                        type="text"
                        wire:model="draft"
                        placeholder="Γράψτε την ερώτησή σας…"
                        wire:loading.attr="disabled"
                    />
                </x-filament::input.wrapper>
                <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="send">
                    Αποστολή
                </x-filament::button>
                @if (count($transcript) > 0)
                    <x-filament::button type="button" color="gray" wire:click="clearChat">
                        Καθαρισμός
                    </x-filament::button>
                @endif
            </form>

            <div wire:loading wire:target="send" class="text-xs text-gray-400 dark:text-gray-500">Σκέφτομαι…</div>
        </div>
    </x-filament::section>
</x-filament-panels::page>
