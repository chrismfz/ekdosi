<?php

namespace App\Services\CustomerLedger;

use Livewire\Wireable;

/**
 * Καρτέλα Πελάτη: complete result of CustomerLedgerBuilder::build().
 *
 * Plain value object so Blade views render without re-querying. Wraps
 * the four sections of the customer ledger:
 *
 * - $stats: quick stats (header strip)
 * - $aging: 0-30 / 31-60 / 61-90 / 90+ days outstanding buckets
 * - $yearly: per-year breakdown rows
 * - $ledger: chronological row stream (invoices + payments with
 *            running balance), already filtered by the requested
 *            year / type / status if any
 *
 * All money values are floats in tenant currency (defaults EUR).
 * Date filters live on the $appliedFilters echo so the view can
 * render a "Filtered by year X" indicator without holding state.
 */
final readonly class CustomerLedgerResult implements Wireable
{
    /**
     * @param  array{
     *     ytd_net: float,
     *     ytd_gross: float,
     *     ytd_paid: float,
     *     balance: float,
     *     oldest_unpaid_days: ?int,
     *     last_activity_at: ?string,
     *     total_invoices_lifetime: int,
     * }  $stats
     * @param  array{
     *     bucket_0_30: float,
     *     bucket_31_60: float,
     *     bucket_61_90: float,
     *     bucket_90_plus: float,
     * }  $aging
     * @param  array<int, array{
     *     year: int,
     *     invoice_count: int,
     *     net: float,
     *     gross: float,
     *     paid: float,
     *     year_end_balance: float,
     * }>  $yearly
     * @param  array<int, array{
     *     date: string,
     *     type: 'invoice'|'payment',
     *     invoice_id: ?int,
     *     payment_id: ?int,
     *     code: ?string,
     *     reference: string,
     *     invoice_type_code: ?string,
     *     debit: float,
     *     credit: float,
     *     running_balance: float,
     *     mydata_state: ?string,
     *     mydata_mark: ?string,
     *     is_receipt_group: bool,
     *     allocations: ?list<array{label: string, amount: float, invoice_id: ?int, invcode: ?string}>,
     * }>  $ledger
     * @param  array{
     *     year: ?int,
     *     invoice_type_id: ?int,
     *     paid_status: ?string,
     * }  $appliedFilters
     *
     * Φ3 — a `payment` ledger row with `is_receipt_group: true` is ONE
     * «έμβασμα/είσπραξη» (a PaymentAllocator batch sharing one
     * `payments.reference`): its `credit` is the SUM of the batch and
     * `allocations` drills down to each underlying Payment (per settled
     * invoice + an optional on-account «Πίστωση / προκαταβολή» line).
     * Ungrouped payments (NULL reference) keep `is_receipt_group: false`
     * and `allocations: null`.
     */
    public function __construct(
        public array $stats,
        public array $aging,
        public array $yearly,
        public array $ledger,
        public array $appliedFilters,
    ) {}

    public function hasAnyActivity(): bool
    {
        return $this->stats['total_invoices_lifetime'] > 0;
    }

    /**
     * Livewire Wireable: snapshot serialization (page → frontend) and
     * hydration (frontend → page). Required because the Καρτέλα Page
     * stores this DTO in a public Livewire property; without this,
     * Livewire throws "Property type not supported" during the
     * dehydration that runs on every render — surfacing as a 404
     * page in production because the page never finishes rendering.
     *
     * The shape mirrors the constructor 1:1 — all properties are
     * already simple arrays so no further serialization needed.
     */
    public function toLivewire(): array
    {
        return [
            'stats' => $this->stats,
            'aging' => $this->aging,
            'yearly' => $this->yearly,
            'ledger' => $this->ledger,
            'appliedFilters' => $this->appliedFilters,
        ];
    }

    public static function fromLivewire($value): self
    {
        return new self(
            stats: $value['stats'],
            aging: $value['aging'],
            yearly: $value['yearly'],
            ledger: $value['ledger'],
            appliedFilters: $value['appliedFilters'],
        );
    }
}
