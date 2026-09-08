<?php

namespace App\Mcp\Support;

use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Resolves WHICH company/companies an external MCP caller acts on — the
 * multi-tenant equivalent of the in-app «Βοηθός»'s `Filament::getTenant()`. The
 * cardinal rule (docs/ai-assistant-blueprint.md) holds on this channel too: a
 * company is NEVER trusted from free text the model invents. The caller may
 * NAME a company (or "all") explicitly, but the choice is always validated
 * server-side against the caller's real access — a member can only ever reach
 * their own companies; a system super_admin reaches every tenant (exactly what
 * the panel's tenant switcher already gives them, no more).
 *
 * Selection (cfm-style, `node`/`node="all"` → `company`/`company="all"`):
 *   - a slug/id  → that ONE company (validated).
 *   - "all"      → fan out over every company the caller may access; the caller
 *                  loops per company and returns a per-company map (no merge).
 *   - omitted    → a tenant-bound Sanctum token's company; else the caller's sole
 *                  company; else refuse and list the options.
 */
class McpTenantResolver
{
    /**
     * Back-compat single-company resolve (used where exactly one company is
     * expected). Delegates to resolveTargets(…, null).
     *
     * @return array{0: ?Company, 1: ?string} [company, errorMessage] — exactly one is non-null.
     */
    public function resolve(User $user): array
    {
        $r = $this->resolveTargets($user, null);

        return $r['error'] !== null ? [null, $r['error']] : [$r['companies'][0], null];
    }

    /**
     * Resolve the target company/companies for a call.
     *
     * @param  ?string  $company  the tool's `company` argument: a slug/id, "all", or null.
     * @return array{companies: list<Company>, error: ?string, fannedOut: bool}
     */
    public function resolveTargets(User $user, ?string $company): array
    {
        $company = $company !== null ? trim($company) : null;
        $wantsAll = $company !== null && strcasecmp($company, 'all') === 0;

        // A tenant-bound Sanctum token is LOCKED to its company — it can neither
        // fan out nor be pointed at another company (that binding is the scope).
        $boundId = $this->boundCompanyId($user);
        if ($boundId !== null) {
            $bound = Company::find($boundId);
            if ($bound === null) {
                return $this->fail('Η εταιρεία του token δεν υπάρχει πλέον.');
            }
            if (! $user->canAccessTenant($bound)) {
                return $this->fail('Δεν έχετε πρόσβαση στην εταιρεία του token.');
            }
            if ($wantsAll) {
                return $this->fail('Το token είναι δεμένο στην εταιρεία «'.$bound->slug.'» — δεν κάνει fan-out. Χρησιμοποίησε OAuth ή token χωρίς δέσμευση εταιρείας.');
            }
            if ($company !== null && $company !== '' && ! $this->matches($bound, $company)) {
                return $this->fail('Το token είναι δεμένο στην εταιρεία «'.$bound->slug.'»· δεν μπορείτε να ζητήσετε άλλη.');
            }

            return ['companies' => [$bound], 'error' => null, 'fannedOut' => false];
        }

        // Unbound (OAuth, or a cross-company token): the `company` arg drives it.
        if ($wantsAll) {
            $all = $this->accessibleCompanies($user);
            if ($all->isEmpty()) {
                return $this->fail('Δεν ανήκετε σε καμία εταιρεία.');
            }

            return ['companies' => $all->all(), 'error' => null, 'fannedOut' => true];
        }

        if ($company !== null && $company !== '') {
            $target = Company::findBySlugOrId($company);
            if ($target === null) {
                return $this->fail('Δεν βρέθηκε εταιρεία «'.$company.'».');
            }
            if (! $this->mayAccess($user, $target)) {
                return $this->fail('Δεν έχετε πρόσβαση στην εταιρεία «'.$target->slug.'».');
            }

            return ['companies' => [$target], 'error' => null, 'fannedOut' => false];
        }

        // No company named → the sole company, else refuse and list the options.
        $accessible = $this->accessibleCompanies($user);
        if ($accessible->count() === 1) {
            return ['companies' => [$accessible->first()], 'error' => null, 'fannedOut' => false];
        }
        if ($accessible->isEmpty()) {
            return $this->fail('Δεν ανήκετε σε καμία εταιρεία.');
        }

        return $this->fail('Προσδιόρισε εταιρεία στο «company» (ή "all"). Διαθέσιμες: '.$accessible->pluck('slug')->implode(', ').'.');
    }

    /**
     * Every company the caller may act on: a system super_admin reaches all
     * tenants (same as the panel switcher); everyone else only their memberships.
     *
     * @return Collection<int, Company>
     */
    public function accessibleCompanies(User $user): Collection
    {
        if ($user->isSystemSuperAdmin()) {
            return Company::query()->orderBy('slug')->get();
        }

        return $user->companies()->orderBy('slug')->get();
    }

    private function mayAccess(User $user, Company $company): bool
    {
        return $user->isSystemSuperAdmin() || $user->canAccessTenant($company);
    }

    private function matches(Company $company, string $arg): bool
    {
        return $company->slug === $arg || (string) $company->getKey() === $arg;
    }

    /**
     * @return array{companies: list<Company>, error: string, fannedOut: bool}
     */
    private function fail(string $message): array
    {
        return ['companies' => [], 'error' => $message, 'fannedOut' => false];
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
