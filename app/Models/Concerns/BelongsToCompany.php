<?php

namespace App\Models\Concerns;

use App\Models\Scopes\CompanyScope;

/**
 * Marks a model as tenant-owned (has a `company_id`) and applies the
 * {@see CompanyScope} global scope so queries are auto-filtered to the ambient
 * tenant ({@see \App\Support\Tenancy\CompanyContext}) when one is set.
 *
 * Read-only by design: it does NOT auto-fill `company_id` on create — Filament
 * sets it via the tenant relationship, and CLI/services set it explicitly. We
 * only constrain reads, which is where the cross-tenant leak risk lives.
 *
 * Uses Laravel's `boot{Trait}` auto-boot so models keep their own `booted()`.
 */
trait BelongsToCompany
{
    public static function bootBelongsToCompany(): void
    {
        static::addGlobalScope(new CompanyScope);
    }
}
