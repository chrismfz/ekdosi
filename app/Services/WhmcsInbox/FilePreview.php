<?php

namespace App\Services\WhmcsInbox;

use App\Models\Customer;
use App\Models\InvoiceType;

/**
 * Preview shape rendered in the "File at AADE" modal. Mirrors what
 * WhmcsInvoiceMapper::map() returns + the resolved Customer + InvoiceType
 * so the modal can render the header card without re-querying.
 *
 * NOT a Livewire-snapshotted value — only used during the modal render
 * lifecycle. If Stage B-2 needs to roundtrip this through Livewire
 * snapshots (e.g. operator changes the customer dropdown and we
 * re-render the preview), implement Wireable like CustomerLedgerResult.
 */
final readonly class FilePreview
{
    /**
     * @param  array<string, mixed>  $header
     * @param  array<int, array<string, mixed>>  $lines
     * @param  array{net_total: float, vat_total: float, gross_total: float, vat_breakdown: array}  $totals
     * @param  array{whmcs_invoice_id: int, whmcs_userid: int, whmcs_date: string, whmcs_total: float}  $source
     */
    public function __construct(
        public array $header,
        public array $lines,
        public array $totals,
        public array $source,
        public Customer $customer,
        public InvoiceType $invoiceType,
    ) {
    }
}
