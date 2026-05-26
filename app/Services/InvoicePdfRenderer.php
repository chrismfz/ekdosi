<?php

namespace App\Services;

use App\Models\Invoice;
use Barryvdh\DomPDF\Facade\Pdf;
use Endroid\QrCode\Builder\Builder;

/**
 * Renders an invoice to PDF bytes via DomPDF + Blade.
 *
 * Bare-bones layout for PR #26 — readable, legally adequate, but not
 * production-polished. PR #27 layers per-tenant templates, logo
 * placement, multi-language, etc.
 *
 * QR code (AADE-issued URL) is embedded as a data-URI PNG so the
 * Blade template doesn't need filesystem access — DomPDF reads it
 * inline. Only rendered when invoice.mydata_url is populated
 * (filed invoices); drafts get a "DRAFT — NOT FILED" badge instead.
 */
class InvoicePdfRenderer
{
    public function render(Invoice $invoice): string
    {
        $invoice->loadMissing(['lines', 'invoiceType', 'customer', 'company', 'paymentMethod']);

        $qrDataUri = $invoice->mydata_url
            ? $this->renderQrDataUri($invoice->mydata_url)
            : null;

        return Pdf::loadView('invoices.pdf', [
            'invoice' => $invoice,
            'tenant' => $invoice->company,
            'qrDataUri' => $qrDataUri,
            'totals' => $this->totalsView($invoice),
        ])
            ->setPaper('A4', 'portrait')
            ->output();
    }

    /**
     * Build the QR PNG inline and return as a data: URI ready for an
     * <img src="..."> attribute. DomPDF handles data URIs natively.
     */
    private function renderQrDataUri(string $url): string
    {
        // endroid/qr-code v6: Builder is a final readonly class
        // constructed with all options, then build() returns a Result.
        // (v5's fluent Builder::create()->writer(...)->build() API
        // was replaced — single constructor + named args is the v6 way.)
        $result = (new Builder(
            writer: new \Endroid\QrCode\Writer\PngWriter(),
            data: $url,
            encoding: new \Endroid\QrCode\Encoding\Encoding('UTF-8'),
            errorCorrectionLevel: \Endroid\QrCode\ErrorCorrectionLevel::Medium,
            size: 200,
            margin: 8,
        ))->build();

        return $result->getDataUri();
    }

    /**
     * Build the per-VAT-rate breakdown for the totals table. Uses the
     * same InvoiceVatBreakdown port of CALCULATE_VAT_FOR_INVOICE that
     * the submitter uses, so the printed PDF reconciles exactly with
     * what we filed at AADE.
     */
    private function totalsView(Invoice $invoice): array
    {
        $breakdown = InvoiceVatBreakdown::for($invoice);
        return [
            'rows' => $breakdown->rows,
            'totalNet' => $breakdown->totalNet(),
            'totalVat' => $breakdown->totalVat(),
            'totalGross' => $breakdown->totalGross(),
            'withhold' => (float) ($invoice->withhold_amount ?? 0),
            'payable' => round(
                $breakdown->totalGross() - (float) ($invoice->withhold_amount ?? 0),
                2,
            ),
        ];
    }
}
