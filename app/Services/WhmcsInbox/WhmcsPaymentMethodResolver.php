<?php

namespace App\Services\WhmcsInbox;

use App\Models\WhmcsPaymentMap;

/**
 * Resolves the ekdosi payment method for a WHMCS invoice from its gateway
 * (`paymentmethod`) via the tenant's {@see WhmcsPaymentMap} declarations. A hit
 * returns the mapped payment_method_id (which carries the §8.12 type + due-days);
 * a miss returns null so the caller falls back to the invoice type default —
 * unchanged behaviour for an unmapped gateway. Built per invoice-mapping (one map
 * query each), mirroring {@see WhmcsIncomeClassifier}; the whole tenant map is loaded
 * once into memory so a many-line invoice still costs a single query.
 */
class WhmcsPaymentMethodResolver
{
    /** @param  array<string, int>  $map  normalised gateway => payment_method_id */
    private function __construct(private array $map) {}

    public static function forCompany(int $companyId): self
    {
        // Only load a mapping whose target method still EXISTS (the default scope
        // excludes soft-deleted) AND is SETTLED (due_days = 0). The gateway map only
        // ever applies to a PAID invoice (see the mapper), so a settled method is the
        // only correct target — a credit-term (due_days>0) one would leave a paid
        // invoice reading as a permanent open receivable. A map that no longer meets
        // both conditions is simply not loaded → the caller falls back to the invoice
        // type default (safe), instead of applying a wrong/soft-deleted method.
        $map = WhmcsPaymentMap::query()
            ->where('company_id', $companyId)
            ->whereHas('paymentMethod', fn ($q) => $q->where('due_days', 0))
            ->get(['whmcs_gateway', 'payment_method_id'])
            ->mapWithKeys(fn (WhmcsPaymentMap $row) => [
                WhmcsPaymentMap::normaliseGateway($row->whmcs_gateway) => (int) $row->payment_method_id,
            ])
            ->all();

        return new self($map);
    }

    /**
     * The ekdosi payment_method_id mapped to a WHMCS gateway, or null when the
     * gateway is blank / unmapped (the caller then keeps the invoice type default).
     */
    public function resolve(?string $gateway): ?int
    {
        $key = WhmcsPaymentMap::normaliseGateway($gateway);

        return $key === '' ? null : ($this->map[$key] ?? null);
    }

    public function isEmpty(): bool
    {
        return $this->map === [];
    }
}
