<?php

namespace App\Services\Whmcs;

use Livewire\Wireable;

/**
 * Result of CustomerWhmcsLedger::fetchFor(). Plain value object so
 * Blade views / Filament modals can render without re-querying.
 *
 * $rows is a list of enriched row arrays with this shape:
 *   [
 *     'whmcs_id'      => 5001,           // int
 *     'date'          => '2026-05-15',   // string YYYY-MM-DD
 *     'datepaid'      => '2026-05-15 12:34:56' | null,
 *     'total'         => '50.00',        // string from WHMCS
 *     'currency'      => 'EUR',
 *     'status'        => 'Paid' | 'Unpaid' | 'Cancelled' | 'Refunded',
 *     'invoiced_flag' => 0 | 1 | null,   // legacy prepare_for_ekdosi
 *     'ekdosi_state'  => [
 *         'kind' => 'staged' | 'historic' | 'absent',
 *
 *         // when kind=staged (pending_whmcs_invoices row exists)
 *         'pending_id' => 42,
 *         'status'     => 'pending_review' | 'filed' | 'rejected' | 'held',
 *         'mark'       => '4000123' | null,
 *
 *         // when kind=historic (whmcs_invoice_log row exists)
 *         'invoice_id' => 12345,    // ekdosi invoices.id
 *         'invoice_legacy_id' => 6543,  // legacy ekdosi invoice id (for display)
 *
 *         // when kind=absent (no ekdosi-side trace)
 *         // (no extra fields)
 *     ],
 *   ]
 *
 * $error is non-null when the fetch failed (auth, unreachable, etc.).
 * Callers render either the rows OR the error message.
 */
final readonly class CustomerWhmcsLedgerResult implements Wireable
{
    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    public function __construct(
        public array $rows,
        public ?string $error = null,
        public int $stagedCount = 0,
        public int $historicCount = 0,
        public int $absentCount = 0,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->rows === [];
    }

    public function total(): int
    {
        return count($this->rows);
    }

    /**
     * Livewire Wireable: required because the Καρτέλα Page stores
     * this DTO in a public Livewire property. Same reasoning as
     * CustomerLedgerResult — without this, Livewire's snapshot
     * serialization throws "Property type not supported" and the
     * page returns 404 in production.
     */
    public function toLivewire(): array
    {
        return [
            'rows'          => $this->rows,
            'error'         => $this->error,
            'stagedCount'   => $this->stagedCount,
            'historicCount' => $this->historicCount,
            'absentCount'   => $this->absentCount,
        ];
    }

    public static function fromLivewire($value): self
    {
        return new self(
            rows:          $value['rows'],
            error:         $value['error'],
            stagedCount:   $value['stagedCount'],
            historicCount: $value['historicCount'],
            absentCount:   $value['absentCount'],
        );
    }
}
