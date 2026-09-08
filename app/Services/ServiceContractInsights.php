<?php

namespace App\Services;

use App\Enums\ServiceContractStatus;
use App\Models\ServiceContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Read-only headline figures for recurring services, shared by the dashboard
 * widgets. Extracted out of the widgets so the money-adjacent MRR sum is
 * unit-testable without mounting Filament. Every method is explicitly scoped by
 * company_id (the caller passes the tenant key) — no reliance on the global
 * CompanyScope, so it's correct from CLI/tests too.
 */
class ServiceContractInsights
{
    public function __construct(private readonly int $companyId) {}

    /** Active contracts. */
    public function activeCount(): int
    {
        return $this->base()->where('status', ServiceContractStatus::Active->value)->count();
    }

    /** Suspended contracts (dunning state). */
    public function suspendedCount(): int
    {
        return $this->base()->where('status', ServiceContractStatus::Suspended->value)->count();
    }

    /** Active contracts whose next_due_date falls within the next $days days. */
    public function renewalsDueWithin(int $days = 30): int
    {
        return $this->base()
            ->where('status', ServiceContractStatus::Active->value)
            ->whereNotNull('next_due_date')
            ->whereDate('next_due_date', '>=', Carbon::today())
            ->whereDate('next_due_date', '<=', Carbon::today()->addDays($days))
            ->count();
    }

    /**
     * Monthly Recurring Revenue: Σ over Active RECURRING contracts of
     * amount × quantity ÷ billing_cycle months. One-Time (null months) and
     * any non-Active contract are excluded. Returns a 2dp-rounded float (EUR).
     *
     * Money note: amount is the NET recurring price per unit (the same value
     * the renewal action treats as the line's net unit price); quantity is the
     * unit count. MRR is a normalised display figure, never a billed amount.
     */
    public function monthlyRecurringRevenue(): float
    {
        $sum = 0.0;

        $this->base()
            ->where('status', ServiceContractStatus::Active->value)
            ->get(['amount', 'quantity', 'billing_cycle'])
            ->each(function (ServiceContract $c) use (&$sum) {
                $months = $c->billing_cycle?->months();
                if ($months === null || $months <= 0) {
                    return; // One-Time / unknown — not recurring revenue.
                }
                $sum += ((float) $c->amount * (float) ($c->quantity ?: 1)) / $months;
            });

        return round($sum, 2);
    }

    private function base(): Builder
    {
        return ServiceContract::query()->where('company_id', $this->companyId);
    }
}
