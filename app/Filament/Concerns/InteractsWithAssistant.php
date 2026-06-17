<?php

namespace App\Filament\Concerns;

use App\Models\Company;
use App\Models\User;
use App\Services\Assistant\AssistantRunner;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Locked;

/**
 * Shared chat state + send loop for the two AI «Βοηθός» surfaces (the dedicated
 * page and the floating widget). Both keep a display `transcript` + the running
 * Anthropic-format `messages`; only the persistence differs (the widget overrides
 * afterAssistantTurn() to stash the conversation in the session so it survives
 * navigation on the non-SPA panel).
 */
trait InteractsWithAssistant
{
    /**
     * #[Locked]: these are SERVER-managed (set only by runAssistant) and round-trip
     * to the client. Locking makes Livewire reject any client mutation, so a user
     * with devtools can't tamper the transcript or smuggle fake tool_result blocks
     * into `messages` (which is fed straight to the model).
     *
     * @var list<array{role:string,text:string}>
     */
    #[Locked]
    public array $transcript = [];

    /** @var list<array<string,mixed>> */
    #[Locked]
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

        // Per-incident ceiling (the monthly cap is the cost ceiling): throttle
        // rapid-fire sends per user+tenant before any work/API call.
        $perMinute = (int) config('ekdosi.ai.rate_per_minute', 15);
        $rlKey = 'ai-chat:'.$tenant->getKey().':'.$user->getKey();
        if (RateLimiter::tooManyAttempts($rlKey, $perMinute)) {
            $this->transcript[] = ['role' => 'system', 'text' => 'Πολλά αιτήματα σε λίγο χρόνο — περιμένετε λίγο και ξαναδοκιμάστε.'];
            $this->afterAssistantTurn();

            return;
        }
        RateLimiter::hit($rlKey, 60);

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
