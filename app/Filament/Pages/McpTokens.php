<?php

namespace App\Filament\Pages;

use App\Models\Company;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Livewire\Attributes\Locked;

/**
 * «Τα κλειδιά MCP μου» — self-service minting of the tenant-bound Sanctum bearer
 * token that authenticates an MCP client (Claude Desktop / CLI / curl) against
 * this app's `/mcp` endpoint. It is the panel equivalent of
 * `php artisan ekdosi:mcp-token <email> --tenant=<slug>`: the token binds to the
 * CURRENTLY ACTIVE tenant (McpTenantResolver reads its `tenant:{id}` ability) and
 * authenticates THIS user — per-tool access stays gated by that user's Shield
 * permissions, exactly as in the panel (MCP.md §2–3).
 *
 * NOT for the claude.ai remote connector — that speaks OAuth 2.1 and needs no
 * token here (MCP.md §8).
 *
 * Gating: `View:McpTokens` (Shield page permission). A bearer token BYPASSES the
 * interactive login AND 2FA, so it is deliberately admin-only: company_admin gets
 * it automatically (it is not in ADMIN_FORBIDDEN_RESOURCES), operator does NOT (it
 * is not in OPERATOR_PERMISSION_MAP), super_admin via the Gate::before bypass.
 * Grant it to an operator explicitly (role picker) only when they truly need API
 * access.
 *
 * Isolation: a user sees + revokes ONLY their OWN tokens, and only those bound to
 * the active tenant — a token for another tenant (or another user) never surfaces
 * here and is never revocable from here.
 */
class McpTokens extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-key';

    protected string $view = 'filament.pages.mcp-tokens';

    /**
     * The freshly-minted plaintext token. Shown immediately after minting and
     * cleared on the next action or a page reload — never re-fetchable. #[Locked]
     * so the client cannot tamper with (or inject) it across Livewire roundtrips.
     */
    #[Locked]
    public ?string $plainToken = null;

    public static function shouldRegisterNavigation(): bool
    {
        return false; // reached from the user menu, not the sidebar
    }

    public static function canAccess(): bool
    {
        return Filament::getTenant() instanceof Company
            && (bool) auth()->user()?->can('View:McpTokens');
    }

    public function getTitle(): string
    {
        return 'Τα κλειδιά MCP μου';
    }

    /**
     * This user's MCP tokens bound to the ACTIVE tenant, newest first. Filtered in
     * PHP on the abilities array — NEVER whereJsonContains (unsupported on sqlite,
     * which the test suite runs on). Fetching all-then-filter is a deliberate
     * small-N call: a user holds a handful of tokens, so a DB-specific narrowing
     * isn't worth the portability cost.
     *
     * @return list<array<string, mixed>>
     */
    public function tokens(): array
    {
        $user = auth()->user();
        if ($user === null) {
            return [];
        }

        $ability = $this->tenantAbility();

        return $user->tokens()
            ->latest('id')
            ->get()
            ->filter(fn ($token): bool => in_array($ability, (array) $token->abilities, true))
            ->map(fn ($token): array => [
                'id' => $token->getKey(),
                'name' => (string) $token->name,
                'created' => optional($token->created_at)->format('Y-m-d H:i') ?? '—',
                'last_used' => $token->last_used_at?->diffForHumans() ?? 'Ποτέ',
            ])
            ->values()
            ->all();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')
                ->label('Δημιουργία token')
                ->icon('heroicon-o-plus')
                ->modalHeading('Νέο MCP token')
                ->modalDescription('Δεμένο στην τρέχουσα εταιρεία. Θα εμφανιστεί ΜΙΑ φορά — αντίγραψέ το αμέσως.')
                ->modalSubmitActionLabel('Δημιουργία')
                ->schema([
                    TextInput::make('name')
                        ->label('Όνομα (για να το ξεχωρίζεις)')
                        ->placeholder('π.χ. laptop, claude-desktop (κενό → mcp:<εταιρεία>)')
                        ->maxLength(120),
                ])
                ->action(function (array $data): void {
                    $user = auth()->user();
                    if ($user === null) {
                        return;
                    }

                    $company = $this->tenant();
                    // Fall back to a slug label only on a TRULY empty name — an
                    // explicit "0" is a valid name, so test the trimmed string, not
                    // its truthiness (?: would relabel "0").
                    $name = trim((string) ($data['name'] ?? ''));
                    $name = $name === '' ? 'mcp:'.$company->slug : $name;

                    $new = $user->createToken($name, ['tenant:'.$company->getKey()]);
                    $this->plainToken = $new->plainTextToken;

                    Notification::make()
                        ->title('Το token δημιουργήθηκε')
                        ->body('Αντίγραψέ το τώρα — δεν εμφανίζεται ξανά.')
                        ->success()
                        ->send();
                }),
        ];
    }

    /**
     * Revoke ONE of this user's tokens — only when it is bound to the active
     * tenant (double-gate: THIS user's token AND this tenant's ability).
     */
    public function revoke(int|string $id): void
    {
        // A prior mint's one-time reveal must not linger once the operator acts again.
        $this->plainToken = null;

        $user = auth()->user();
        if ($user === null) {
            return;
        }

        $token = $user->tokens()->whereKey($id)->first();
        if ($token === null || ! in_array($this->tenantAbility(), (array) $token->abilities, true)) {
            return;
        }

        $token->delete();

        Notification::make()->title('Το token ανακλήθηκε.')->success()->send();
    }

    /** The active tenant (canAccess guarantees it's a Company before we get here). */
    private function tenant(): Company
    {
        $tenant = Filament::getTenant();
        if (! $tenant instanceof Company) {
            abort(403);
        }

        return $tenant;
    }

    /** The ability string that binds a token to the active tenant. */
    private function tenantAbility(): string
    {
        return 'tenant:'.$this->tenant()->getKey();
    }
}
