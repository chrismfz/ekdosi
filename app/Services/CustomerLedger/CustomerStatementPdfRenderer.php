<?php

namespace App\Services\CustomerLedger;

use App\Models\Customer;
use App\Support\CustomerLanguage;
use App\Support\Filename;
use App\Support\Pdf\PdfLabels;
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
                // Chronological (old→new) — a printed statement reads top→bottom.
                'ledger' => $result->chronologicalLedger(),
                'generatedAt' => now(),
                // i18n: a statement is a LIVE document (regenerated on demand, not a
                // frozen legal snapshot), so it follows the customer's current
                // communication language (el/en/both). Unlike the statement EMAIL — a
                // single-language body that collapses 'both' to English (forCustomerMail)
                // — the PDF can stay bilingual, like the invoice/quote PDFs.
                'L' => PdfLabels::for(CustomerLanguage::forCustomer($customer)),
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
        $slug = Filename::slug($customer->name, 'customer');

        return 'kartela-'.$slug.'-'.now()->format('Ymd').'.pdf';
    }
}
