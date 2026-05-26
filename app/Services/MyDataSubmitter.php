<?php

namespace App\Services;

use App\Contracts\EInvoiceSubmitter;
use App\Enums\MyDataMode;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\MyDataMark;
use Carbon\Carbon;
use Firebed\AadeMyData\Enums\CountryCode;
use Firebed\AadeMyData\Enums\CurrencyCode;
use Firebed\AadeMyData\Enums\InvoiceType as AadeInvoiceType;
use Firebed\AadeMyData\Enums\VatCategory as AadeVatCategory;
use Firebed\AadeMyData\Exceptions\MyDataAuthenticationException;
use Firebed\AadeMyData\Exceptions\MyDataConnectionException;
use Firebed\AadeMyData\Exceptions\MyDataException;
use Firebed\AadeMyData\Exceptions\MyDataTimeoutException;
use Firebed\AadeMyData\Http\CancelInvoice;
use Firebed\AadeMyData\Http\MyDataRequest;
use Firebed\AadeMyData\Http\RequestTransmittedDocs;
use Firebed\AadeMyData\Http\SendInvoices;
use Firebed\AadeMyData\Models\Counterpart;
use Firebed\AadeMyData\Models\Invoice as AadeInvoice;
use Firebed\AadeMyData\Models\InvoiceDetails;
use Firebed\AadeMyData\Models\InvoiceHeader;
use Firebed\AadeMyData\Models\InvoiceSummary;
use Firebed\AadeMyData\Models\Issuer;
use Firebed\AadeMyData\Models\ResponseDoc;
use Firebed\AadeMyData\Models\TaxesTotals;
use Firebed\AadeMyData\Models\TaxTotals;
use Firebed\AadeMyData\Xml\InvoicesDocWriter;
use GuzzleHttp\Handler\MockHandler;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Real myDATA submitter. Wraps the firebed/aade-mydata library and
 * adapts our domain (Invoice + InvoiceLine + Company) to its
 * (AadeInvoice + InvoiceHeader + InvoiceDetails + InvoiceSummary +
 * TaxesTotals).
 *
 * Per-tenant credentials: firebed uses STATIC state for credentials
 * (MyDataRequest::init() sets self::$user_id etc.), which is fine for
 * the typical FPM/Apache single-request-per-process model. We
 * defensively call init() before EVERY operation so even if a
 * previous request left stale credentials in static state, ours win.
 * If we ever move to Octane / Roadrunner / parallel queue workers,
 * the static state becomes a contention point — track in CLAUDE.md.
 *
 * Replaces the legacy MARK_AI0 Firebird trigger by updating the
 * Invoice's mydata_* mirror columns inside the same DB transaction
 * as the new mydata_marks row. Either both succeed or neither does.
 *
 * Dry-run mode: builds the full XML payload, persists a
 * mydata_action='DRY_RUN' audit row with the XML, and never POSTs to
 * AADE. Lets operators preview exactly what would be submitted from
 * the Filament invoice view page (and via the artisan smoke command).
 * The Invoice's mirror columns are NOT updated on dry-run — there's
 * no real MARK to mirror.
 */
class MyDataSubmitter implements EInvoiceSubmitter
{
    public function __construct(
        private readonly Company $tenant,
        /**
         * Optional mock handler for tests. firebed's MyDataRequest
         * accepts a Guzzle MockHandler that intercepts outbound HTTP
         * — same pattern as the AadeRegistryLookup. Production code
         * never passes this.
         */
        private readonly ?MockHandler $mockHandler = null,
    ) {}

    public function submit(Invoice $invoice): MyDataMark
    {
        // Guard against re-submitting an already-filed invoice. Critical
        // for the ETL cutover path: imported legacy invoices arrive with
        // mydata_state='VALID' and a real MARK. Without this guard, an
        // operator clicking Submit on an imported invoice would file it
        // again at AADE (UID idempotency below mitigates but doesn't
        // fully prevent — defense in depth).
        if ($invoice->mydata_state === 'VALID') {
            throw new RuntimeException(
                "Invoice {$invoice->invcode} was already filed at myDATA under MARK ".
                ($invoice->mydata_mark ?: '?').'. '.
                'Use the Cancel action and re-issue if a correction is needed.'
            );
        }

        $payload = $this->buildAadeInvoice($invoice);
        $xml = $this->payloadToXml($payload);

        $this->initFirebed();

        try {
            $response = (new SendInvoices())->handle($payload);
        } catch (MyDataAuthenticationException $e) {
            $this->logFailure($invoice, 'auth', $e);
            throw new RuntimeException('myDATA rejected credentials. Check Company → myDATA submission tab.', 0, $e);
        } catch (MyDataTimeoutException | MyDataConnectionException $e) {
            $this->logFailure($invoice, 'transport', $e);
            throw new RuntimeException('myDATA endpoint unreachable. Try again later.', 0, $e);
        } catch (MyDataException $e) {
            $this->logFailure($invoice, 'protocol', $e);
            throw new RuntimeException('myDATA submission failed: '.$e->getMessage(), 0, $e);
        } catch (Throwable $e) {
            $this->logFailure($invoice, 'other', $e);
            throw new RuntimeException('myDATA submission failed unexpectedly.', 0, $e);
        }

        return $this->persistResponse($invoice, $payload, $xml, $response);
    }

    /**
     * Build the would-be submission XML and persist a DRY_RUN audit row
     * WITHOUT contacting AADE. Safe on any mode (off / sandbox /
     * production). Lets operators inspect what the real submitter
     * would send.
     *
     * Deliberately a SEPARATE PUBLIC METHOD from submit() — the prior
     * design (`submit($invoice, $dryRun = false)`) was a footgun: any
     * caller that forgot to pass the named argument would file for
     * real. Code review correctly flagged this. Now grep-able: every
     * caller of submit() definitely submits; every caller of
     * previewXml() definitely doesn't.
     */
    public function previewXml(Invoice $invoice): MyDataMark
    {
        $payload = $this->buildAadeInvoice($invoice);
        $xml = $this->payloadToXml($payload);
        return $this->recordDryRun($invoice, $xml);
    }

    public function cancel(Invoice $invoice, string $reason = ''): MyDataMark
    {
        // Guard: refuse to double-cancel. Once mydata_state='CANCELLED'
        // we don't want a second CANCEL call to AADE (which would
        // either be rejected or produce a duplicate CANCEL audit row).
        if ($invoice->mydata_state === 'CANCELLED') {
            throw new RuntimeException(
                "Invoice {$invoice->invcode} is already cancelled at myDATA (state=CANCELLED). ".
                'Refusing to double-cancel.'
            );
        }

        // Read the actual MARK from the audit history — NOT from the
        // mirror column. Reasoning: if a previous submit succeeded at
        // AADE but the DB write failed (and was later retried, producing
        // a second MARK), the mirror reflects only the LATEST mark
        // while the older one is still active at AADE. We cancel the
        // most recent INSERT mark (because that's what the AADE-side
        // dedup logic should have collapsed onto via the UID), but
        // surface a warning if multiple INSERT marks exist for the
        // same invoice — that's a hint of an earlier orphan.
        $inserts = MyDataMark::query()
            ->where('invoice_id', $invoice->id)
            ->where('mydata_action', 'INSERT')
            ->whereNotNull('mark')
            ->orderByDesc('id')
            ->get();

        if ($inserts->isEmpty()) {
            throw new RuntimeException(
                "Cannot cancel invoice {$invoice->invcode} — no INSERT MARK on file. ".
                'The invoice was never submitted to myDATA.'
            );
        }

        if ($inserts->count() > 1) {
            Log::warning('myDATA cancel: multiple INSERT MARKs found — cancelling latest only', [
                'invoice_id' => $invoice->id,
                'invcode' => $invoice->invcode,
                'marks' => $inserts->pluck('mark')->all(),
                'note' => 'Earlier MARKs may be orphan filings at AADE. Manual reconciliation required.',
            ]);
        }

        $markToCancel = (string) $inserts->first()->mark;

        $this->initFirebed();

        try {
            $response = (new CancelInvoice())->handle((int) $markToCancel);
        } catch (Throwable $e) {
            $this->logFailure($invoice, 'cancel', $e);
            throw new RuntimeException('myDATA cancellation failed: '.$e->getMessage(), 0, $e);
        }

        return DB::transaction(function () use ($invoice, $response, $reason, $markToCancel) {
            $mark = MyDataMark::create([
                'company_id' => $invoice->company_id,
                'invoice_id' => $invoice->id,
                'mark' => $markToCancel,
                'mydata_action' => 'CANCEL',
                'request' => $reason !== '' ? "Cancel reason: {$reason}" : null,
                'response' => $this->responseToString($response),
                'mark_date' => now()->toDateString(),
                'mark_time' => now()->toTimeString(),
            ]);

            // MARK_AI0 trigger replacement: flip the invoice's
            // mirror columns to reflect cancellation. The MARK is
            // preserved for audit but state becomes CANCELLED.
            $invoice->forceFill([
                'mydata_state' => 'CANCELLED',
            ])->save();

            return $mark;
        });
    }

    public function testConnection(): bool
    {
        $this->initFirebed();

        try {
            // RequestTransmittedDocs with a tight date range — minimal
            // query, just verifies creds + reachability. AADE returns
            // an empty doc list if nothing was filed today; we don't
            // care about content, only that the call doesn't fault.
            // First arg is `string $mark = ''` (NOT nullable) — pass
            // empty string, not null, to avoid a TypeError that would
            // be caught by the generic Throwable handler below and
            // misclassified as a transport failure.
            $action = new RequestTransmittedDocs();
            $action->handle('', now()->subDay()->toDateString(), now()->toDateString());
            return true;
        } catch (MyDataAuthenticationException) {
            return false;
        } catch (Throwable $e) {
            // Non-auth errors (timeout, transport) reach the operator
            // as "couldn't reach AADE" — don't claim credentials are
            // bad when we don't know that.
            Log::warning('myDATA test connection failed (non-auth)', [
                'company_id' => $this->tenant->getKey(),
                'exception' => get_class($e),
            ]);
            throw $e;
        }
    }

    // ---- internals ------------------------------------------------------

    private function initFirebed(): void
    {
        $aadeId = $this->tenant->mydata_aade_id;
        $subKey = $this->tenant->mydata_subscription_key;  // decrypted by Eloquent cast

        if (empty($aadeId) || empty($subKey)) {
            throw new RuntimeException(
                'myDATA credentials are not configured for this tenant. '.
                'Set mydata_aade_id and mydata_subscription_key on the Company.'
            );
        }

        $env = $this->tenant->mydata_mode_enum === MyDataMode::Production ? 'prod' : 'dev';

        MyDataRequest::init($aadeId, $subKey, $env);

        // Always set the handler — passing null explicitly RESETS
        // firebed's static $handler. Without this, once any test in
        // the process sets a MockHandler, every subsequent submitter
        // in the same process (queue worker, Octane, test suite)
        // inherits the leftover handler and silently intercepts real
        // submissions. Always-reset is the safe default.
        MyDataRequest::setHandler($this->mockHandler);
    }

    /**
     * Build the firebed AadeInvoice domain object from our Invoice.
     * Field mapping is the heart of the bridge between our schema
     * and AADE's payload shape. Keep this small and readable; any
     * special-case logic (e.g. intra-community zero-VAT rules) lives
     * in dedicated value objects, not inline here.
     */
    private function buildAadeInvoice(Invoice $invoice): AadeInvoice
    {
        $invoice->loadMissing(['lines', 'invoiceType', 'customer']);

        // Draft guard: code (ΑΑ) is allocated by InvoiceNumberer at
        // issue-time. A code of 0 means the InvoiceNumberer wasn't run
        // (draft invoice, test fixture, broken save path). Building an
        // AADE payload with <aa>0</aa> would either be rejected with
        // an opaque error OR — worse — accepted as a real filing for a
        // technically-valid invoice number 0. Fail fast.
        if (! $invoice->code || (int) $invoice->code < 1) {
            throw new RuntimeException(
                "Invoice {$invoice->invcode} has no ΑΑ number (code=".($invoice->code ?? 'null').'). '.
                'Allocate via App\\Services\\InvoiceNumberer before submission.'
            );
        }

        $type = $invoice->invoiceType?->mydata_type
            ?? throw new RuntimeException(
                "Invoice {$invoice->invcode} cannot be submitted — its invoice_type "
                ."has no mydata_type set. Configure on the InvoiceType resource."
            );

        $vatBreakdown = InvoiceVatBreakdown::for($invoice);

        $issuer = (new Issuer())
            ->setVatNumber($this->tenant->afm ?? throw new RuntimeException('Issuer company has no AFM'))
            ->setCountry(CountryCode::GR)
            ->setBranch(0);

        $counterpart = $this->buildCounterpart($invoice, $type);

        $header = (new InvoiceHeader())
            ->setSeries($invoice->invoiceType->code)
            ->setAa((string) $invoice->code)
            ->setIssueDate(Carbon::parse($invoice->issued_at)->toDateString())
            ->setInvoiceType($type)
            ->setCurrency(CurrencyCode::EUR);

        $details = [];
        $lineNo = 1;
        foreach ($invoice->lines as $line) {
            $details[] = (new InvoiceDetails())
                ->setLineNumber($lineNo++)
                ->setNetValue((float) $line->net_price)
                ->setVatCategory($this->vatCategoryFor((float) $line->vat_percent))
                ->setVatAmount(round((float) $line->gross_price - (float) $line->net_price, 2))
                ->setQuantity((float) $line->qty);
        }

        // TaxesTotals takes the TaxTotals[] in its constructor — no
        // setTaxes() method exists. The trait-provided generic set()
        // is also unavailable on TypeArray subclasses.
        $taxesTotals = new TaxesTotals(array_map(
            fn ($row) => (new TaxTotals())
                ->setTaxType(1) // 1 = VAT
                ->setTaxCategory($this->vatCategoryFor($row['rate']))
                ->setUnderlyingValue($row['net'])
                ->setTaxAmount($row['vat']),
            $vatBreakdown->rows,
        ));

        $summary = (new InvoiceSummary())
            ->setTotalNetValue($vatBreakdown->totalNet())
            ->setTotalVatAmount($vatBreakdown->totalVat())
            ->setTotalGrossValue($vatBreakdown->totalGross());

        $aade = (new AadeInvoice())
            ->setIssuer($issuer)
            ->setInvoiceHeader($header)
            ->setInvoiceDetails($details)
            ->setInvoiceSummary($summary)
            ->setTaxesTotals($taxesTotals);

        if ($counterpart) {
            $aade->setCounterpart($counterpart);
        }

        // UID idempotency: AADE dedupes resubmissions by UID. Without
        // it, a transient timeout that the operator retries results in
        // a duplicate filing with a new MARK — tax-compliance breach.
        // firebed's guessUid() computes the deterministic UID from
        // VAT + date + branch + type + series + AA, so the same logical
        // invoice always produces the same UID and AADE returns the
        // ORIGINAL MARK on retry. Critical for the ETL cutover scenario
        // and for any "click Submit twice on a slow network" path.
        $aade->set('uid', $aade->guessUid());

        return $aade;
    }

    /**
     * Decide whether to attach a Counterpart and how to construct it,
     * based on the AADE invoice type.
     *
     *   - Retail types (11.x):    Counterpart is FORBIDDEN.
     *   - B2B / standard types:   Counterpart is REQUIRED.
     *   - Special types (13.x receiver-side, 17.x adjustments): out of
     *                             scope for this issuer flow.
     *
     * Filing the wrong shape (e.g. attaching Counterpart to an 11.2
     * ΑΠΥ for an AFM-bearing customer) is a common AADE rejection.
     */
    private function buildCounterpart(Invoice $invoice, string $type): ?Counterpart
    {
        // Retail (Λιανικής) types forbid Counterpart even if customer
        // has an AFM (operator booked a B2B-style customer into a
        // retail receipt — common with WHMCS-originated invoices).
        if (str_starts_with($type, '11.')) {
            return null;
        }

        $customer = $invoice->customer;
        if (! $customer || empty($customer->afm)) {
            throw new RuntimeException(
                "Invoice {$invoice->invcode} (type $type) requires a customer with AFM, but ".
                ($customer ? 'AFM is empty' : 'no customer is set').'. '.
                'Either fill the customer AFM, or change the invoice type to a retail variant (11.x).'
            );
        }

        return (new Counterpart())
            ->setVatNumber($customer->afm)
            ->setCountry($invoice->country ?: 'GR')
            ->setBranch(0);
    }

    /**
     * Map a numeric VAT rate to AADE's VatCategory enum (1..8).
     * Values from AADE myDATA spec — kept conservative; unknown
     * rates throw so we don't silently file with the wrong category.
     *
     * IMPORTANT: 0% is NOT mapped here. Real-world 0% lines need a
     * separate `vatExemptionCategory` field (intra-community supply
     * vs domestic exempt vs reverse-charge vs out-of-scope) that we
     * don't yet capture. Throwing forces operators to wait for the
     * exemption-category mechanism rather than silently filing wrong
     * — tracked as a deferred follow-up.
     */
    private function vatCategoryFor(float $rate): int
    {
        return match (true) {
            abs($rate - 24) < 0.01 => 1,  // 24% standard
            abs($rate - 13) < 0.01 => 2,  // 13% reduced
            abs($rate - 6) < 0.01 => 3,   // 6% super-reduced
            abs($rate - 17) < 0.01 => 4,  // 17% (islands)
            abs($rate - 9) < 0.01 => 5,   // 9% (islands)
            abs($rate - 4) < 0.01 => 6,   // 4% (islands)
            abs($rate - 0) < 0.01 => throw new RuntimeException(
                'Cannot submit 0% VAT line without an exemption category. '.
                'AADE distinguishes domestic exempt / intra-community supply / reverse-charge / out-of-scope. '.
                'Per-line vat_exemption_category support is tracked in CLAUDE.md as a deferred follow-up; '.
                'for now this submitter refuses 0% lines rather than file with the wrong category.'
            ),
            default => throw new RuntimeException(
                "VAT rate {$rate}% has no AADE VatCategory mapping. ".
                'Configure the VAT category on the lookup resource, or use category 8 (no VAT) manually.'
            ),
        };
    }

    private function payloadToXml(AadeInvoice $payload): string
    {
        return (new InvoicesDocWriter())->asXml(
            new \Firebed\AadeMyData\Models\InvoicesDoc([$payload])
        );
    }

    private function responseToString(ResponseDoc $response): string
    {
        // firebed returns a strongly-typed ResponseDoc; serialize for
        // the audit row's response column. The full XML is what AADE
        // legally requires us to retain.
        return (string) $response;
    }

    private function recordDryRun(Invoice $invoice, string $xml): MyDataMark
    {
        return DB::transaction(fn () => MyDataMark::create([
            'company_id' => $invoice->company_id,
            'invoice_id' => $invoice->id,
            'mark' => null,
            'mydata_action' => 'DRY_RUN',
            'request' => $xml,
            'response' => null,
            'mark_date' => now()->toDateString(),
            'mark_time' => now()->toTimeString(),
        ]));
    }

    private function persistResponse(
        Invoice $invoice,
        AadeInvoice $payload,
        string $xml,
        ResponseDoc $response,
    ): MyDataMark {
        $responseRows = $response->getResponses() ?? [];
        $firstResponse = $responseRows[0] ?? null;

        if ($firstResponse === null || $firstResponse->getStatusCode() !== 'Success') {
            $errors = $firstResponse ? $this->describeResponseErrors($firstResponse) : 'no response';
            throw new RuntimeException("myDATA rejected the submission: {$errors}");
        }

        $mark = (string) $firstResponse->getInvoiceMark();
        $qrUrl = $firstResponse->getQrUrl();

        return DB::transaction(function () use ($invoice, $payload, $xml, $response, $mark, $qrUrl) {
            $audit = MyDataMark::create([
                'company_id' => $invoice->company_id,
                'invoice_id' => $invoice->id,
                'mark' => $mark,
                'mydata_action' => 'INSERT',
                'invoice_url' => $qrUrl,
                'request' => $xml,
                'response' => $this->responseToString($response),
                'mark_date' => now()->toDateString(),
                'mark_time' => now()->toTimeString(),
            ]);

            // Legacy MARK_AI0 trigger replacement. forceFill bypasses
            // $fillable, which deliberately omits the mydata_* mirror
            // columns to prevent operator forms from spoofing them
            // (PR #23 review finding). The submitter is the ONLY
            // legitimate writer.
            $invoice->forceFill([
                'mydata_sent' => true,
                'mydata_state' => 'VALID',
                'mydata_mark' => $mark,
                'mydata_url' => $qrUrl,
                // firebed may return getInvoiceType() as either a raw
                // string ("1.1") OR a BackedEnum case (AadeInvoiceType::TYPE_1_1)
                // depending on how the header was set (we always pass
                // a string, but the setter may coerce). varchar(5)
                // would overflow on an enum case name like 'TYPE_1_1'
                // AND it's the wrong value semantically. Normalise.
                'mydata_type' => $this->normalizeInvoiceTypeForStorage(
                    $payload->getInvoiceHeader()->getInvoiceType()
                ),
            ])->save();

            return $audit;
        });
    }

    /**
     * Coerce whatever firebed's getInvoiceType() returns into the
     * canonical "1.1" / "11.2" / etc. AADE invoice-type string for
     * storage in the varchar(5) mydata_type column.
     */
    private function normalizeInvoiceTypeForStorage(mixed $type): ?string
    {
        if ($type === null) {
            return null;
        }
        if ($type instanceof \BackedEnum) {
            return (string) $type->value;
        }
        return (string) $type;
    }

    private function describeResponseErrors($response): string
    {
        if (! method_exists($response, 'getErrors')) {
            return $response->getStatusCode() ?? 'unknown';
        }
        $errs = $response->getErrors() ?? [];
        return implode('; ', array_map(
            fn ($e) => method_exists($e, 'getMessage')
                ? $e->getMessage()
                : (string) $e,
            $errs,
        )) ?: 'unknown';
    }

    private function logFailure(Invoice $invoice, string $kind, Throwable $e): void
    {
        Log::warning('myDATA submission failure', [
            'company_id' => $this->tenant->getKey(),
            'invoice_id' => $invoice->id,
            'invcode' => $invoice->invcode,
            'kind' => $kind,
            'exception' => get_class($e),
            'message' => $e->getMessage(),
        ]);
    }
}
