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

    public function submit(Invoice $invoice, bool $dryRun = false): MyDataMark
    {
        $payload = $this->buildAadeInvoice($invoice);
        $xml = $this->payloadToXml($payload);

        if ($dryRun) {
            return $this->recordDryRun($invoice, $xml);
        }

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

    public function cancel(Invoice $invoice, string $reason = ''): MyDataMark
    {
        if (empty($invoice->mydata_mark)) {
            throw new RuntimeException(
                "Cannot cancel invoice {$invoice->invcode} — no MARK on file. ".
                'The invoice was never submitted to myDATA, or its mirror columns are stale.'
            );
        }

        $this->initFirebed();

        try {
            $response = (new CancelInvoice())->handle((int) $invoice->mydata_mark);
        } catch (Throwable $e) {
            $this->logFailure($invoice, 'cancel', $e);
            throw new RuntimeException('myDATA cancellation failed: '.$e->getMessage(), 0, $e);
        }

        return DB::transaction(function () use ($invoice, $response, $reason) {
            $mark = MyDataMark::create([
                'company_id' => $invoice->company_id,
                'invoice_id' => $invoice->id,
                'mark' => $invoice->mydata_mark, // refers to the ORIGINAL mark being cancelled
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
            $action = new RequestTransmittedDocs();
            $action->handle(null, now()->subDay()->toDateString(), now()->toDateString());
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

        if ($this->mockHandler !== null) {
            MyDataRequest::setHandler($this->mockHandler);
        }
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

        $counterpart = $invoice->customer && $invoice->customer->afm
            ? (new Counterpart())
                ->setVatNumber($invoice->customer->afm)
                ->setCountry($invoice->country ?? 'GR')
                ->setBranch(0)
            : null;

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

        $taxesTotals = (new TaxesTotals())
            ->setTaxes(array_map(
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

        return $aade;
    }

    /**
     * Map a numeric VAT rate to AADE's VatCategory enum (1..8).
     * Values from AADE myDATA spec — kept conservative; unknown
     * rates throw so we don't silently file with the wrong category.
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
            abs($rate - 0) < 0.01 => 7,   // 0% (exempt with right of deduction)
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
                'mydata_type' => $payload->getInvoiceHeader()->getInvoiceType(),
            ])->save();

            return $audit;
        });
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
