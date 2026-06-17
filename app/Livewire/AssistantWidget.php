<?php

namespace App\Livewire;

use App\Filament\Concerns\InteractsWithAssistant;
use App\Filament\Pages\Assistant;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Session;
use Livewire\Component;

/**
 * Floating AI «Βοηθός» — injected on every panel page (render hook) so the
 * operator can chat while navigating. Shares the AssistantRunner engine with the
 * dedicated Assistant page via InteractsWithAssistant. Because the panel is NOT
 * an SPA (every navigation is a full reload), the conversation is persisted in
 * the SESSION (per tenant+user) and rehydrated on mount — so it survives moving
 * between pages. Cleared on «Καθαρισμός» or logout (session lifetime).
 */
class AssistantWidget extends Component
{
    use InteractsWithAssistant;

    public bool $open = false;

    public function mount(): void
    {
        $state = Session::get($this->sessionKey(), []);
        $this->transcript = $state['transcript'] ?? [];
        $this->messages = $state['messages'] ?? [];
    }

    public function send(): void
    {
        $this->runAssistant();
    }

    public function clearChat(): void
    {
        $this->resetAssistant();
    }

    protected function afterAssistantTurn(): void
    {
        Session::put($this->sessionKey(), [
            'transcript' => $this->transcript,
            'messages' => $this->messages,
        ]);
    }

    private function sessionKey(): string
    {
        return 'ai_chat:'.(Filament::getTenant()?->getKey() ?? '0').':'.(auth()->id() ?? '0');
    }

    public function render()
    {
        // Defensive: if availability flips mid-session, render nothing.
        if (! Assistant::assistantAvailable()) {
            return view('livewire.assistant-widget-empty');
        }

        return view('livewire.assistant-widget');
    }
}
