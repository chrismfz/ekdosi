<?php

namespace App\Services;

use App\Models\Invoice;
use App\Support\MyData\QrImage;
use App\Support\Pdf\PdfLabels;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

/**
 * Renders an invoice to PDF bytes via DomPDF + the polished Blade
 * template in resources/views/invoices/pdf.blade.php.
 *
 * What ships here (PR #27):
 *   - Polished single-template layout that adapts per myDATA invoice
 *     type (delivery / retail / credit / standard).
 *   - Tenant logo support: read companies.logo_path (relative to the
 *     `public` disk by default), inline as a data URI so DomPDF
 *     doesn't need filesystem read at render time and the resulting
 *     PDF is self-contained (relevant for the mail attachment flow).
 *   - QR code (AADE-issued URL) embedded as a data-URI PNG.
 *   - Resource guard: scoped ini_set for memory_limit + max_execution_
 *     time so a 200-line ΣΔΕΠ can't take down the FPM worker. Tracked
 *     PR #26 deferral closed.
 *
 * What's NOT here (deferred):
 *   - Multi-language. Hard-coded el-GR labels. i18n is its own slice.
 *   - Per-tenant template overrides. Single template; if a tenant
 *     genuinely needs a different layout we'd add a `pdf_template_id`
 *     column and resolve via the InvoicePdfRendererFactory.
 *   - Background queuing of huge renders. Synchronous up-front render
 *     is fine for typical 1-20 line invoices; the resource guard
 *     keeps the long tail from breaking the worker.
 */
class InvoicePdfRenderer
{
    /**
     * Memory + time guard for synchronous renders. DomPDF on a
     * cumulative invoice with 200+ lines comfortably uses 200-300MB.
     * We don't want to bump global config (other workers don't need
     * this much), so set per-render via ini_set and restore via
     * try/finally so a thrown PDF builder doesn't leak the elevated
     * limits to subsequent requests on the same FPM worker.
     */
    private const RENDER_MEMORY_LIMIT = '512M';

    private const RENDER_TIME_LIMIT_SECONDS = 60;

    public function render(Invoice $invoice): string
    {
        $invoice->loadMissing([
            'lines', 'invoiceType', 'customer', 'company', 'paymentMethod',
            // For the «Σχετικά παραστατικά» block (credit-note / delivery links).
            // Only ISSUED credit notes (local_status active) — never a not-yet-issued
            // draft, which would assert a reversal on the customer PDF before it
            // legally exists. The Filament panel (operator) still shows all.
            'creditNotes' => fn ($q) => $q->where('local_status', 'active'),
            'creditedInvoice',
            // Same rule for linked delivery notes — only ISSUED ones (active), not a
            // draft or a cancelled δελτίο, on the customer-facing copy.
            'deliveryNotes' => fn ($q) => $q->where('local_status', 'active'),
        ]);

        // Tenant-relation siblings the template references that aren't
        // always relations on Invoice. Defensive lazy-load via
        // optional() in the template; here we just preload the ones
        // that ARE relations.
        if (method_exists($invoice, 'deliveryMethod')) {
            $invoice->loadMissing('deliveryMethod');
        }
        if (method_exists($invoice, 'distributionAim')) {
            $invoice->loadMissing('distributionAim');
        }

        $qrDataUri   = $invoice->mydata_url ? $this->renderQrDataUri($invoice->mydata_url) : null;
        $logoDataUri = $this->loadLogoDataUri($invoice->company);

        $previousMemory = ini_get('memory_limit');
        $previousTime   = ini_get('max_execution_time');

        try {
            // DomPDF reads ini at render time, not at load-view time —
            // so the elevated limits cover the whole pipeline (Blade
            // compile + DomPDF layout + PDF emit).
            @ini_set('memory_limit', self::RENDER_MEMORY_LIMIT);
            @set_time_limit(self::RENDER_TIME_LIMIT_SECONDS);

            return Pdf::loadView('invoices.pdf', [
                'invoice'     => $invoice,
                'tenant'      => $invoice->company,
                'qrDataUri'   => $qrDataUri,
                'logoDataUri' => $logoDataUri,
                'totals'      => $this->totalsView($invoice),
                'customerBalance' => $this->customerBalanceView($invoice),
                'L'           => PdfLabels::for(PdfLabels::resolveLanguage($invoice->language, $invoice->country)),
            ])
                ->setPaper('A4', 'portrait')
                ->output();
        } finally {
            // Restore previous limits so a long-lived FPM worker
            // doesn't carry the elevated values into the next request.
            @ini_set('memory_limit', $previousMemory);
            @set_time_limit((int) $previousTime);
        }
    }

    /**
     * Build the QR PNG inline and return as a data: URI ready for an
     * <img src="..."> attribute. DomPDF handles data URIs natively.
     */
    private function renderQrDataUri(string $url): string
    {
        // Render at high resolution (vs the 200px screen default) so the QR
        // stays crisp when dompdf scales the PNG down to ~32mm in print — a
        // low-res render of the dense ~150-char AADE URL rasterised fuzzy and
        // phones misread it (truncated/wrong host on scan).
        return QrImage::dataUri($url, 600);
    }

    /**
     * Load tenant logo from storage and inline as a data URI. Returns
     * null when the tenant has no logo or the file is missing — the
     * template renders blank header space in that case.
     *
     * Tenants store the path RELATIVE to the configured disk (default
     * `public`), e.g. `logos/myip.png`. Filament's FileUpload field on
     * CompanyForm writes that shape automatically.
     *
     * MIME sniffing via finfo because operators upload whatever — PNG,
     * JPG, SVG (DomPDF only reads raster, so SVG would render as a
     * broken image; we warn at upload time instead of crashing here).
     */
    private function loadLogoDataUri(?\App\Models\Company $tenant): ?string
    {
        if (! $tenant || empty($tenant->logo_path)) {
            return null;
        }

        // Wrap in try/catch because Flysystem's WhitespacePathNormalizer
        // THROWS PathTraversalDetected on `../` segments (verified at
        // vendor/league/flysystem/src/WhitespacePathNormalizer.php:36)
        // — it does NOT return false. The Filament FileUpload field
        // writes safe paths, but admin-level DB access or an ETL
        // anomaly could land a hostile value in companies.logo_path,
        // and we don't want every PDF render to crash. Treat any
        // filesystem-side rejection as "no logo" and continue.
        try {
            $disk = Storage::disk('public');
            if (! $disk->exists($tenant->logo_path)) {
                return null;
            }

            $bytes = $disk->get($tenant->logo_path);
        } catch (\League\Flysystem\FilesystemException $e) {
            return null;
        } catch (\Throwable $e) {
            // Belt-and-suspenders: any other unexpected exception from
            // the storage layer also yields "no logo" rather than
            // breaking the whole PDF.
            return null;
        }

        if ($bytes === null || $bytes === '') {
            return null;
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: 'application/octet-stream';

        return 'data:'.$mime.';base64,'.base64_encode($bytes);
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
            'rows'       => $breakdown->rows,
            'totalNet'   => $breakdown->totalNet(),
            'totalVat'   => $breakdown->totalVat(),
            'totalGross' => $breakdown->totalGross(),
            // Additional taxes (myDATA taxesTotals) surfaced so the «Πληρωτέο» on
            // the PDF == the collectible (and the AADE gross). Withholding is shown
            // as a reduction ONLY when it actually reduces the gross (§8.4 8/9/10
            // are informational).
            'fees'       => (float) ($invoice->fees_amount ?? 0),
            'stamp'      => (float) ($invoice->stamp_duty_amount ?? 0),
            'other'      => (float) ($invoice->other_taxes_amount ?? 0),
            'deductions' => (float) ($invoice->deductions_amount ?? 0),
            'withhold'   => $invoice->withholdingReducesGross() ? (float) ($invoice->withhold_amount ?? 0) : 0.0,
            'payable'    => round($breakdown->totalGross() + $invoice->additionalTaxAdjustment(), 2),
        ];
    }

    /**
     * The «Υπόλοιπο πελάτη» block (legacy «ΝΕΟ ΥΠΟΛΟΙΠΟ»): Προηγούμενο / αυτό το
     * παραστατικό / Νέο, derived from the issue-time snapshot so it stays STABLE
     * on reprint. Returns null (block omitted) unless:
     *   • the snapshot was captured (credit-term/credit-note invoice), AND
     *   • the toggle is on — per-customer override wins, else the tenant default.
     *
     * Νέο = the snapshot (the customer's running balance right after this issue);
     * Προηγούμενο = Νέο − this document's signed contribution.
     */
    private function customerBalanceView(Invoice $invoice): ?array
    {
        if ($invoice->customer_balance_snapshot === null) {
            return null;
        }

        $customer = $invoice->customer;
        $enabled = $customer?->show_balance_on_pdf
            ?? (bool) ($invoice->company?->show_customer_balance_on_pdf);
        if (! $enabled) {
            return null;
        }

        $new = round((float) $invoice->customer_balance_snapshot, 2);
        $current = round($invoice->customerBalanceContribution(), 2);

        return [
            'previous' => round($new - $current, 2),
            'current'  => $current,
            'new'      => $new,
        ];
    }
}
