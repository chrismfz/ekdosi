<?php

namespace App\Support\Tenancy;

use App\Models\Company;
use Closure;

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
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function actAs(Company|int|string $company, Closure $callback): mixed
    {
        $previous = $this->companyId;
        $this->set($company);

        try {
            return $callback();
        } finally {
            $this->companyId = $previous;
        }
    }
}
