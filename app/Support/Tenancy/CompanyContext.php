<?php

namespace App\Support\Tenancy;

use App\Models\Company;
use Closure;
use Filament\Facades\Filament;

/**
 * The ambient "current company" for tenant scoping outside a Filament request.
 *
 * Filament scopes its RESOURCE queries to the panel tenant, but a raw
 * `Invoice::where(...)` in an action / job / command is NOT auto-scoped — the
 * long-standing latent leak (CLAUDE.md). This holder is the single source the
 * `CompanyScope` global scope reads:
 *
 *   - Filament requests set it on the `TenantSet` event (AppServiceProvider),
 *     so every tenant-owned model query inside the panel is auto-filtered.
 *   - CLI / queue code has no ambient tenant; it either scopes explicitly
 *     (`->where('company_id', ...)`, the existing pattern) or opts in with
 *     `actAs($company, fn () => ...)` to get the same auto-scoping.
 *
 * When the id is null (no context) the scope is a NO-OP — non-breaking for the
 * explicit-scoped CLI paths. A future strict mode can flip the null case to
 * throw once those paths all use `actAs`.
 *
 * Bound as a singleton (see AppServiceProvider) so the id lives for the request
 * / command lifetime.
 */
class CompanyContext
{
    private int|string|null $companyId = null;

    public function id(): int|string|null
    {
        return $this->companyId;
    }

    public function has(): bool
    {
        return $this->companyId !== null;
    }

    public function set(Company|int|string|null $company): void
    {
        $this->companyId = $company instanceof Company ? $company->getKey() : $company;
    }

    public function clear(): void
    {
        $this->companyId = null;
    }

    /**
     * Run $callback with the context pinned to $company, restoring whatever
     * was set before (nestable). The way CLI/queue code opts into auto-scoping.
     *
     * Inside a panel request it ALSO pins Filament's tenant: Filament's tenancy
     * `creating` hook re-associates every new tenant-owned row to the panel's
     * CURRENT tenant, overriding an explicit company_id. Without this, seeding a
     * newly created company from inside tenant A wrote A's rows (prod 2026-10-30:
     * duplicate '1-ΤΙΜ' on «Δημιουργία Εταιρείας»). Swapped quietly (no
     * TenantSet event) and restored afterwards; a no-op when no panel tenant.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function actAs(Company|int|string $company, Closure $callback): mixed
    {
        $previous = $this->companyId;
        $this->set($company);

        $previousTenant = Filament::getTenant();
        $swapTenant = $previousTenant !== null && (string) $previousTenant->getKey() !== (string) $this->companyId;
        if ($swapTenant) {
            Filament::setTenant(
                $company instanceof Company ? $company : Company::query()->withoutGlobalScopes()->findOrFail($company),
                isQuiet: true,
            );
        }

        try {
            return $callback();
        } finally {
            $this->companyId = $previous;
            if ($swapTenant) {
                Filament::setTenant($previousTenant, isQuiet: true);
            }
        }
    }
}
