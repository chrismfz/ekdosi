<?php

namespace App\Filament\Concerns;

use App\Models\Company;
use App\Models\User;
use App\Services\Assistant\AssistantRunner;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;

/**
 * Shared chat state + send loop for the two AI «Βοηθός» surfaces (the dedicated
 * page and the floating widget). Both keep a display `transcript` + the running
 * Anthropic-format `messages`; only the persistence differs (the widget overrides
 * afterAssistantTurn() to stash the conversation in the session so it survives
 * navigation on the non-SPA panel).
 */
trait InteractsWithAssistant
{
    /** @var list<array{role:string,text:string}> */
    public array $transcript = [];

    /** @var list<array<string,mixed>> */
    public array $messages = [];

    public string $draft = '';

    protected function runAssistant(): void
    {
        $text = trim($this->draft);
        if ($text === '') {
            return;
        }

        $tenant = Filament::getTenant();
        $user = auth()->user();
        if (! $tenant instanceof Company || ! $user instanceof User) {
            return;
        }

        $this->transcript[] = ['role' => 'user', 'text' => $text];
        $this->draft = '';

        $res = app(AssistantRunner::class)->ask($tenant, $user, $text, $this->messages);

        $this->messages = $res['messages'];
        $this->transcript[] = ['role' => $res['blocked'] ? 'system' : 'assistant', 'text' => $res['reply']];

        if ($res['warning']) {
            Notification::make()->title('Πλησιάζετε το μηνιαίο όριο AI')->warning()->send();
        }

        $this->afterAssistantTurn();
    }

    protected function resetAssistant(): void
    {
        $this->transcript = [];
        $this->messages = [];
        $this->draft = '';
        $this->afterAssistantTurn();
    }

    /** Hook for surface-specific persistence (the widget saves to session). */
    protected function afterAssistantTurn(): void {}
}
