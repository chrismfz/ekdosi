<?php

namespace App\Mcp\Support;

use App\Models\Company;
use App\Models\User;

/**
 * Resolves WHICH company an external MCP caller acts as — the multi-tenant
 * equivalent of the in-app «Βοηθός»'s `Filament::getTenant()`. The cardinal
 * rule (docs/ai-assistant-blueprint.md) holds on this channel too: the tenant
 * is NEVER an argument the model controls. There is no `company` parameter on
 * any tool; the company is bound SERVER-SIDE to the bearer token and validated
 * against the user's real membership.
 *
 * Resolution order:
 *   1. A Sanctum token bound to a tenant — `ekdosi:mcp-token --tenant=slug`
 *      stores a `tenant:{id}` ability; we read it back and confirm the user may
 *      access that company (canAccessTenant). A user with 3 companies mints 3
 *      tokens.
 *   2. Otherwise, if the user belongs to exactly ONE company, use it (the common
 *      single-tenant operator — no need to name it).
 *   3. Otherwise refuse with a clear message telling them to bind a tenant. A
 *      multi-company OAuth (claude.ai) connection lands here until per-tenant
 *      OAuth binding ships — use a Sanctum token meanwhile (see MCP.md).
 */
class McpTenantResolver
{
    /**
     * @return array{0: ?Company, 1: ?string} [company, errorMessage] — exactly one is non-null.
     */
    public function resolve(User $user): array
    {
        $boundId = $this->boundCompanyId($user);

        if ($boundId !== null) {
            $company = Company::find($boundId);
            if ($company === null) {
                return [null, 'Η εταιρεία του token δεν υπάρχει πλέον.'];
            }
            if (! $user->canAccessTenant($company)) {
                return [null, 'Δεν έχετε πρόσβαση στην εταιρεία του token.'];
            }

            return [$company, null];
        }

        // No explicit binding → fall back to the sole company, if there is one.
        $companies = $user->companies()->limit(2)->get();
        if ($companies->count() === 1) {
            return [$companies->first(), null];
        }

        if ($companies->isEmpty()) {
            return [null, 'Ο χρήστης δεν ανήκει σε καμία εταιρεία.'];
        }

        return [null, 'Το token δεν είναι δεμένο σε εταιρεία. Δημιουργήστε ένα με: '
            .'php artisan ekdosi:mcp-token <email> --tenant=<slug>.'];
    }

    /**
     * The company id carried by the current Sanctum access token's `tenant:{id}`
     * ability, or null when there is no such binding (or the guard is not Sanctum,
     * e.g. an OAuth access token — those have no Sanctum abilities).
     */
    private function boundCompanyId(User $user): ?int
    {
        if (! method_exists($user, 'currentAccessToken')) {
            return null;
        }

        $token = $user->currentAccessToken();
        if ($token === null) {
            return null;
        }

        // Sanctum's PersonalAccessToken carries an `abilities` array. A Transient
        // token (or a Passport token) will not — hence the guarded access.
        $abilities = $token->abilities ?? null;
        if (! is_array($abilities)) {
            return null;
        }

        foreach ($abilities as $ability) {
            if (is_string($ability) && preg_match('/^tenant:(\d+)$/', $ability, $m) === 1) {
                return (int) $m[1];
            }
        }

        return null;
    }
}
