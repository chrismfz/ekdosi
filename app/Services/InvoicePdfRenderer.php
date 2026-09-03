<?php

namespace App\Services;

use App\Models\BankAccount;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\VatCategory;
use App\Support\MyData\Codes;
use App\Support\MyData\QrImage;
use App\Support\Pdf\PdfLabels;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\FilesystemException;

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
        $previousMemory = ini_get('memory_limit');
        $previousTime = ini_get('max_execution_time');

        try {
            // DomPDF reads ini at render time, not at load-view time —
            // so the elevated limits cover the whole pipeline (Blade
            // compile + DomPDF layout + PDF emit).
            @ini_set('memory_limit', self::RENDER_MEMORY_LIMIT);
            @set_time_limit(self::RENDER_TIME_LIMIT_SECONDS);

            return Pdf::loadView('invoices.pdf', $this->viewData($invoice))
                ->setPaper('A4', 'portrait')
                // isPhpEnabled: the template draws the «Σελίδα X από Y» pager via a
                // DomPDF text-callback (`<script type="text/php">`) because DomPDF 3.x
                // resolves counter(pages) to 0 inside a fixed footer. Safe here: the
                // template is developer-authored and the ONLY interpolated free-text
                // (invoice notes) is e()-escaped before DomPDF sees it, so no
                // user-controlled markup can inject a text/php script.
                ->setOption('isPhpEnabled', true)
                ->output();
        } finally {
            // Restore previous limits so a long-lived FPM worker
            // doesn't carry the elevated values into the next request.
            @ini_set('memory_limit', $previousMemory);
            @set_time_limit((int) $previousTime);
        }
    }

    /**
     * Render the invoice template to HTML (no DomPDF) — the same view data the
     * PDF uses. For tests to assert printed content without parsing PDF bytes.
     * NOT for the production render path: it skips the memory/time guard that
     * render() applies for the DomPDF layout pass.
     */
    public function renderHtml(Invoice $invoice): string
    {
        return view('invoices.pdf', $this->viewData($invoice))->render();
    }

    /**
     * The full data array the invoice template needs. Shared by render() (PDF)
     * and renderHtml() (tests) so they can't drift.
     *
     * @return array<string, mixed>
     */
    public function viewData(Invoice $invoice): array
    {
        $invoice->loadMissing([
            'lines', 'invoiceType', 'customer', 'company', 'paymentMethod', 'bankAccount',
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

        // All the tenant's payment accounts to print (like a Greek τιμολόγιο with
        // several IBANs) — the customer pays via any. Always include THIS invoice's
        // explicitly-linked account too, even if it's hidden/inactive tenant-wide:
        // a per-invoice choice must not be silently dropped by the global flag.
        $bankAccounts = BankAccount::invoiceAccounts($invoice->company_id);
        if ($invoice->bankAccount && ! $bankAccounts->contains('id', $invoice->bankAccount->id)) {
            $bankAccounts = $bankAccounts->push($invoice->bankAccount)->values();
        }

        return [
            'invoice' => $invoice,
            'tenant' => $invoice->company,
            'qrDataUri' => $invoice->mydata_url ? $this->renderQrDataUri($invoice->mydata_url) : null,
            'logoDataUri' => $this->loadLogoDataUri($invoice->company),
            'totals' => $this->totalsView($invoice),
            'customerBalance' => $this->customerBalanceView($invoice),
            'providerEvidence' => $this->providerEvidenceView($invoice),
            'bankAccounts' => $bankAccounts,
            'L' => PdfLabels::for(PdfLabels::resolveLanguage($invoice->language, $invoice->country)),
        ];
    }

    /**
     * PROV-003: when this invoice was filed through a ΥΠΑΗΕΣ provider, A.1112/2025
     * requires the printed representation to carry the provider's identity + the
     * document's provider evidence. Resolved from the FILING mark (the latest
     * PROVIDER_INSERT row) + the provider's config identity — so the correct
     * provider is shown per document even after a channel switch. Returns null for
     * direct-myDATA and unfiled invoices (no provider block on those).
     *
     * @return array<string, mixed>|null
     */
    private function providerEvidenceView(Invoice $invoice): ?array
    {
        // ONE resolver, shared with the invoice page, so print and screen apply
        // identical gates (VALID, not-cancelled, current provider mark, licence
        // present) and never diverge. Memoised on the invoice.
        return $invoice->providerEvidence();
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
    private function loadLogoDataUri(?Company $tenant): ?string
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
        } catch (FilesystemException $e) {
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
            'rows' => $breakdown->rows,
            'totalNet' => $breakdown->totalNet(),
            'totalVat' => $breakdown->totalVat(),
            'totalGross' => $breakdown->totalGross(),
            // Additional taxes (myDATA taxesTotals) surfaced so the «Πληρωτέο» on
            // the PDF == the collectible (and the AADE gross). Withholding is shown
            // as a reduction ONLY when it actually reduces the gross (§8.4 8/9/10
            // are informational).
            'fees' => (float) ($invoice->fees_amount ?? 0),
            'stamp' => (float) ($invoice->stamp_duty_amount ?? 0),
            'other' => (float) ($invoice->other_taxes_amount ?? 0),
            'deductions' => (float) ($invoice->deductions_amount ?? 0),
            'withhold' => $invoice->withholdingReducesGross() ? (float) ($invoice->withhold_amount ?? 0) : 0.0,
            'payable' => round($breakdown->totalGross() + $invoice->additionalTaxAdjustment(), 2),
            // Σύνολο τεμαχίων — the «ΣΥΝΟΛΙΚΗ ΠΟΣΟΤΗΤΑ» a Greek τιμολόγιο shows.
            'totalQty' => (float) $invoice->lines->sum(fn ($l) => (float) $l->qty),
            // DOC-1: the VAT-exemption legal citation for 0% documents.
            'vatExemption' => $this->vatExemptionView($invoice),
        ];
    }

    /**
     * DOC-1 / MYD-007: the §8.3 exemption citation(s) for the 0% lines, derived
     * from the SAME per-line reason that was filed to AADE
     * (invoice_lines.vat_exemption_category) — so the printed legal citation can't
     * diverge from the filed document, and an invoice with several 0% reasons
     * cites each. A line with no per-line snapshot (legacy/imported) falls back to
     * the tenant's single 0%-category reason. Non-throwing: a draft/preview on an
     * unconfigured tenant renders without the note.
     *
     * @return list<array{code:int,label:string}>|null distinct reasons, in use order
     */
    private function vatExemptionView(Invoice $invoice): ?array
    {
        $zeroLines = $invoice->lines->filter(
            fn ($line) => abs((float) $line->vat_percent) < 0.01
        );
        if ($zeroLines->isEmpty()) {
            return null;
        }

        $fallback = $this->tenantSingleZeroReason($invoice);

        $codes = $zeroLines
            ->map(function ($line) use ($fallback) {
                $c = $line->vat_exemption_category;

                return ($c !== null && $c !== '') ? (int) $c : $fallback;
            })
            ->filter(fn ($c) => $c !== null && Codes::vatExemptionExists((int) $c))
            ->map(fn ($c) => (int) $c)
            ->unique()
            ->values();

        if ($codes->isEmpty()) {
            return null;
        }

        return $codes->map(fn (int $code) => [
            'code' => $code,
            'label' => Codes::VAT_EXEMPTION_LABELS[$code] ?? ('Κατηγορία '.$code),
        ])->all();
    }

    /**
     * The tenant's SINGLE configured 0%-category §8.3 reason, or null when none or
     * several exist — the fallback for a 0% line without its own per-line reason.
     * Non-throwing (mirrors the submitter's tenant-wide fallback, but the PDF must
     * render regardless of misconfiguration).
     */
    private function tenantSingleZeroReason(Invoice $invoice): ?int
    {
        $codes = VatCategory::query()
            ->where('company_id', $invoice->company_id)
            ->where('rate', 0)
            ->whereNotNull('vat_exemption_category')
            ->pluck('vat_exemption_category')
            ->map(fn ($c) => (int) $c)
            ->unique()
            ->values();

        return $codes->count() === 1 ? (int) $codes->first() : null;
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
            'current' => $current,
            'new' => $new,
        ];
    }
}
