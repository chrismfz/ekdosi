<?php

namespace App\Filament\Concerns;

use App\Models\AiPendingAction;
use App\Models\Company;
use App\Models\User;
use App\Services\Assistant\AiActionExecutor;
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

    /**
     * The WRITE actions the assistant PREPARED that still await this operator's
     * confirmation — rendered as confirm/cancel cards below the chat. Queried
     * fresh from the DB each render (cheap, tenant+user scoped), so they survive
     * navigation (the non-SPA panel) and can't be tampered client-side.
     *
     * @return list<array{id:int,type:string,summary:string}>
     */
    public function pendingAssistantActions(): array
    {
        $tenant = Filament::getTenant();
        $user = auth()->user();
        if (! $tenant instanceof Company || ! $user instanceof User) {
            return [];
        }

        return AiPendingAction::query()
            ->where('company_id', $tenant->getKey())
            ->where('user_id', $user->getKey())
            ->pending()
            ->latest('id')
            ->limit(5)
            ->get(['id', 'type', 'summary'])
            ->map(fn (AiPendingAction $a): array => [
                'id' => $a->id,
                'type' => $a->type,
                'summary' => $a->summary,
            ])
            ->all();
    }

    public function confirmAssistantAction(int $id): void
    {
        $user = auth()->user();
        $action = $this->ownedPendingAction($id);
        if ($action === null || ! $user instanceof User) {
            return;
        }
        $result = app(AiActionExecutor::class)->confirm($action, $user);
        $this->transcript[] = ['role' => 'system', 'text' => '✓ '.$result];
        $this->afterAssistantTurn();
    }

    public function cancelAssistantAction(int $id): void
    {
        $action = $this->ownedPendingAction($id);
        if ($action === null) {
            return;
        }
        app(AiActionExecutor::class)->cancel($action);
        $this->transcript[] = ['role' => 'system', 'text' => '✕ Ακυρώθηκε.'];
        $this->afterAssistantTurn();
    }

    /**
     * Load a pending action ONLY if it belongs to the current tenant + operator —
     * the client passes an id, never the row, so this is the trust boundary (the
     * executor re-validates again).
     */
    private function ownedPendingAction(int $id): ?AiPendingAction
    {
        $tenant = Filament::getTenant();
        $user = auth()->user();
        if (! $tenant instanceof Company || ! $user instanceof User) {
            return null;
        }

        return AiPendingAction::query()
            ->where('company_id', $tenant->getKey())
            ->where('user_id', $user->getKey())
            ->pending()
            ->find($id);
    }

    /** Hook for surface-specific persistence (the widget saves to session). */
    protected function afterAssistantTurn(): void {}
}
