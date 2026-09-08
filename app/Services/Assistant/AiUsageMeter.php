<?php

namespace App\Services\Assistant;

use App\Models\AiUsageLog;
use App\Models\Company;

/**
 * Per-tenant token metering + cap state for the AI «Βοηθός».
 *
 * Sums the current calendar month's tokens from ai_usage_log for a tenant and
 * compares against the per-company cap (companies.ai_monthly_token_cap) AND the
 * global backstop (config). The runner checks `blocked()` BEFORE each API call
 * and `warning()` for the soft 80% banner. Tenant-scoped explicitly (works on
 * the CLI/queue too, per the CLAUDE.md rule).
 */
class AiUsageMeter
{
    /** Soft-warn threshold (fraction of the cap). */
    private const WARN_AT = 0.80;

    /** Tokens (input+output) used by the tenant in the current calendar month. */
    public function monthlyTokens(Company $tenant): int
    {
        return (int) AiUsageLog::query()
            ->where('company_id', $tenant->getKey())
            ->where('created_at', '>=', now()->startOfMonth())
            ->selectRaw('COALESCE(SUM(input_tokens + output_tokens), 0) AS t')
            ->value('t');
    }

    /**
     * The effective cap for the tenant: the smaller of the per-company cap and
     * the global backstop (ignoring a 0/unset on either side). Null = uncapped.
     */
    public function effectiveCap(Company $tenant): ?int
    {
        $perCompany = (int) ($tenant->ai_monthly_token_cap ?? 0);
        $global = (int) config('ekdosi.ai.global_monthly_token_cap', 0);

        $caps = array_filter([$perCompany, $global], static fn (int $c): bool => $c > 0);

        return $caps === [] ? null : min($caps);
    }

    /** Hard stop: the tenant has reached/exceeded its effective cap. */
    public function blocked(Company $tenant): bool
    {
        $cap = $this->effectiveCap($tenant);

        return $cap !== null && $this->monthlyTokens($tenant) >= $cap;
    }

    /** Soft warn: at/over WARN_AT of the cap (but not yet blocked). */
    public function warning(Company $tenant): bool
    {
        $cap = $this->effectiveCap($tenant);
        if ($cap === null) {
            return false;
        }
        $used = $this->monthlyTokens($tenant);

        return $used < $cap && $used >= (int) ($cap * self::WARN_AT);
    }
}
