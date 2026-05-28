<?php

namespace App\Services\CustomerLedger;

use App\Models\Customer;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Renders a customer Καρτέλα (statement of account) to PDF bytes.
 *
 * Mirrors the memory/time-limit guard pattern of InvoicePdfRenderer.
 * The view is plain HTML + inline CSS (dompdf does NOT understand
 * Tailwind / Filament's compiled CSS), so it is self-contained and
 * unaffected by the front-end asset pipeline.
 */
class CustomerStatementPdfRenderer
{
    /**
     * @param  array{year?: ?int, invoice_type_id?: ?int, paid_status?: ?string}  $filters
     */
    public function render(Customer $customer, array $filters = []): string
    {
        $result = app(CustomerLedgerBuilder::class)->build($customer, $filters);

        $originalMemory = ini_get('memory_limit');
        @ini_set('memory_limit', '512M');
        $restoreTimeLimit = (int) ini_get('max_execution_time');
        @set_time_limit(60);

        try {
            return Pdf::loadView('customers.statement-pdf', [
                'customer' => $customer,
                'company' => $customer->company,
                'stats' => $result->stats,
                'aging' => $result->aging,
                'yearly' => $result->yearly,
                'ledger' => $result->ledger,
                'generatedAt' => now(),
            ])
                ->setPaper('a4', 'portrait')
                ->output();
        } finally {
            @ini_set('memory_limit', $originalMemory);
            @set_time_limit($restoreTimeLimit);
        }
    }

    public function filename(Customer $customer): string
    {
        $slug = preg_replace('/[^A-Za-z0-9_-]/', '', \Illuminate\Support\Str::ascii((string) $customer->name)) ?: 'customer';

        return 'kartela-'.$slug.'-'.now()->format('Ymd').'.pdf';
    }
}
