<?php

namespace App\Models\Scopes;

use App\Support\Tenancy\CompanyContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Global scope that filters tenant-owned models by the ambient
 * {@see CompanyContext} company id.
 *
 *   - context set  → `WHERE company_id = <context>` (qualified, so it survives
 *     joins). Auto-scopes raw model queries inside a Filament request and any
 *     `CompanyContext::actAs()` block.
 *   - context null → NO-OP. The explicit `->where('company_id', ...)` the
 *     CLI/queue paths already use keeps protecting them; nothing breaks.
 *
 * Opt out per query with `->withoutGlobalScope(CompanyScope::class)`.
 */
class CompanyScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $companyId = app(CompanyContext::class)->id();

        if ($companyId === null) {
            return; // no ambient tenant → don't constrain (see class docblock)
        }

        $builder->where($model->qualifyColumn('company_id'), $companyId);
    }
}
