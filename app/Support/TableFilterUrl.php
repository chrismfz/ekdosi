<?php

namespace App\Support;

/**
 * Deep-link into a Filament list with its filters already applied.
 *
 * The one place that knows the QUERY-STRING key. It is **`filters`**, not the
 * `tableFilters` property name: `ListRecords` declares
 * `#[Url(as: 'filters')] public ?array $tableFilters`, so a
 * `?tableFilters[x][value]=y` URL binds to NOTHING and the list lands
 * unfiltered — silently, which is how four drill-downs shipped broken (the
 * dashboard's «Ανεξόφλητα»/«Πρόχειρα» cards, the overdue-invoice notification,
 * the leads-calendar banner). Guarded end-to-end by
 * `Tests\Feature\Filament\ListFilterUrlTest`.
 *
 * Usage:
 *   CustomerResource::getUrl('index', TableFilterUrl::with(
 *       ['balance_status' => ['value' => 'debtor']],
 *       ['sort' => 'outstanding_balance:desc'],
 *   ))
 */
class TableFilterUrl
{
    /** The query-string key Filament binds the table filter state to. */
    public const KEY = 'filters';

    /**
     * @param  array<string, array<string, mixed>>  $filters  filter name => its state (e.g. ['value' => 'debtor'], ['isActive' => true])
     * @param  array<string, mixed>  $extra  other route/query params (tab, sort, tenant…)
     * @return array<string, mixed>
     */
    public static function with(array $filters, array $extra = []): array
    {
        return $extra + [self::KEY => $filters];
    }
}
