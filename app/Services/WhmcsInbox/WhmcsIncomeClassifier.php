<?php

namespace App\Services\WhmcsInbox;

use App\Models\WhmcsIncomeMap;

/**
 * Resolves a WHMCS invoice line's §8.6 income classification from the tenant's
 * {@see WhmcsIncomeMap} declarations: a per-PRODUCT mapping wins, else the line's
 * product GROUP mapping, else nothing (the caller then falls back to the invoice
 * type default + business policy — MYD-006). Loaded once per tenant into memory so
 * a many-line invoice costs one query, not N.
 */
class WhmcsIncomeClassifier
{
    /**
     * @param  array<int, array{class: ?string, category: string}>  $productMap  pid => classification
     * @param  array<int, array{class: ?string, category: string}>  $groupMap  gid => classification
     */
    private function __construct(private array $productMap, private array $groupMap) {}

    public static function forCompany(int $companyId): self
    {
        $product = [];
        $group = [];

        WhmcsIncomeMap::query()
            ->where('company_id', $companyId)
            ->get(['scope', 'whmcs_key', 'income_class', 'income_class_category'])
            ->each(function (WhmcsIncomeMap $row) use (&$product, &$group): void {
                $entry = [
                    'class' => $row->income_class ?: null,
                    'category' => (string) $row->income_class_category,
                ];
                if ($row->scope === WhmcsIncomeMap::SCOPE_PRODUCT) {
                    $product[(int) $row->whmcs_key] = $entry;
                } elseif ($row->scope === WhmcsIncomeMap::SCOPE_GROUP) {
                    $group[(int) $row->whmcs_key] = $entry;
                }
            });

        return new self($product, $group);
    }

    /**
     * The (E3 income class, §8.6 category) a line inherits from its WHMCS product /
     * group mapping. Product override wins over the group; both absent → [null, null]
     * so the submitter's own resolution takes over unchanged.
     *
     * @return array{0: ?string, 1: ?string} [income_class, income_class_category]
     */
    public function resolve(int $whmcsProductId, int $whmcsGroupId): array
    {
        $hit = ($whmcsProductId > 0 ? ($this->productMap[$whmcsProductId] ?? null) : null)
            ?? ($whmcsGroupId > 0 ? ($this->groupMap[$whmcsGroupId] ?? null) : null);

        return $hit === null ? [null, null] : [$hit['class'], $hit['category']];
    }

    public function isEmpty(): bool
    {
        return $this->productMap === [] && $this->groupMap === [];
    }
}
