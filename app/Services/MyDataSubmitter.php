<?php

namespace App\Services;

use App\Contracts\EInvoiceSubmitter;
use App\Enums\MyDataMode;
use App\Jobs\SendInvoiceEmail;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\MyDataMark;
use App\Support\MyData\Codes;
use Carbon\Carbon;
use Firebed\AadeMyData\Enums\CountryCode;
use Firebed\AadeMyData\Enums\CurrencyCode;
use Firebed\AadeMyData\Enums\InvoiceType as AadeInvoiceType;
use Firebed\AadeMyData\Exceptions\MyDataAuthenticationException;
use Firebed\AadeMyData\Exceptions\MyDataConnectionException;
use Firebed\AadeMyData\Exceptions\MyDataException;
use Firebed\AadeMyData\Exceptions\MyDataTimeoutException;
use Firebed\AadeMyData\Http\CancelInvoice;
use Firebed\AadeMyData\Http\MyDataRequest;
use Firebed\AadeMyData\Http\RequestTransmittedDocs;
use Firebed\AadeMyData\Http\SendInvoices;
use Firebed\AadeMyData\Models\Address;
use Firebed\AadeMyData\Models\Counterpart;
use Firebed\AadeMyData\Models\Invoice as AadeInvoice;
use Firebed\AadeMyData\Models\InvoiceDetails;
use Firebed\AadeMyData\Models\InvoiceHeader;
use Firebed\AadeMyData\Models\InvoicesDoc;
use Firebed\AadeMyData\Models\InvoiceSummary;
use Firebed\AadeMyData\Models\Issuer;
use Firebed\AadeMyData\Models\PaymentMethodDetail;
use Firebed\AadeMyData\Models\Response;
use Firebed\AadeMyData\Models\ResponseDoc;
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
        // Symmetric guard: refuse to resubmit a previously-cancelled
        // invoice. AADE's behaviour for UID-dedup against a cancelled
        // filing is undocumented — operator should issue a fresh
        // correction invoice instead.
        if ($invoice->mydata_state === 'CANCELLED') {
            throw new RuntimeException(
                "Invoice {$invoice->invcode} was previously filed and CANCELLED at myDATA. ".
                'Issue a correction invoice (new code) instead of resubmitting.'
            );
        }
        // Belt-and-suspenders catch-all. The two specific guards above
        // cover all values legacy schema ever wrote (legacy/ekdosi-schema.sql:991
        // sets MYDATA_STATE='VALID', :1000 sets 'CANCELLED'). If a future
        // code path or a corrupted import introduces ANY other non-null
        // state, refuse to file blindly rather than treat unknown as
        // "never filed". Loud-fail beats silent double-file. Per the
        // fourth-review finding — defense in depth even though the
        // specific guards are exhaustive for today's data.
        if ($invoice->mydata_state !== null && $invoice->mydata_state !== '') {
            throw new RuntimeException(
                "Invoice {$invoice->invcode} has an unrecognised mydata_state='{$invoice->mydata_state}'. ".
                'Refusing to submit — investigate the state value before retrying. '.
                'Expected null (never filed), VALID (filed), or CANCELLED.'
            );
        }

        $payload = $this->buildAadeInvoice($invoice);
        $xml = $this->payloadToXml($payload);

        $this->initFirebed();

        // Hold the action instance so we can call getResponseXML() on it
        // afterwards. ResponseDoc itself does NOT support __toString;
        // the raw XML lives on the action via the HasResponseDom trait
        // (see vendor/firebed/aade-mydata/src/Http/Traits/HasResponseDom.php).
        $action = new SendInvoices;

        try {
            $response = $action->handle($payload);
        } catch (MyDataAuthenticationException $e) {
            $this->logFailure($invoice, 'auth', $e);
            throw new RuntimeException('myDATA rejected credentials. Check Company → myDATA submission tab.', 0, $e);
        } catch (MyDataTimeoutException|MyDataConnectionException $e) {
            $this->logFailure($invoice, 'transport', $e);
            throw new RuntimeException('myDATA endpoint unreachable. Try again later.', 0, $e);
        } catch (MyDataException $e) {
            $this->logFailure($invoice, 'protocol', $e);
            throw new RuntimeException('myDATA submission failed: '.$e->getMessage(), 0, $e);
        } catch (Throwable $e) {
            $this->logFailure($invoice, 'other', $e);
            throw new RuntimeException('myDATA submission failed unexpectedly.', 0, $e);
        }

        $responseXml = $action->getResponseXML() ?? '';

        $mark = $this->persistResponse($invoice, $payload, $xml, $response, $responseXml);

        // PR #27: dispatch the customer-mail job after a successful
        // VALID filing IF the tenant has opted in via
        // auto_email_on_mydata_accept. The job re-fetches the invoice,
        // renders a fresh PDF, and routes to customer.email + the
        // tenant's audit BCC. Skipped silently when:
        //   - tenant flag is false
        //   - customer has no email (job handler logs + returns)
        //   - this submitter was reached via DRY_RUN (not this path)
        $this->dispatchAutoEmailIfEnabled($invoice);

        return $mark;
    }

    /**
     * Best-effort dispatch of the customer-mail job. Silent on every
     * tenant-opted-out path; logs (doesn't throw) if the dispatcher
     * itself fails — we don't want a queue-connection hiccup to mask
     * a successful AADE filing from the operator. The mail can always
     * be re-sent via the ViewInvoice "Resend email" action.
     *
     * NOTE on DB::afterCommit: Laravel's transaction manager fires the
     * callback IMMEDIATELY when there's no active transaction (verified
     * at vendor/laravel/framework/.../DatabaseTransactionsManager.php
     * :205). So in the IssueInvoice (CreateInvoice) path — which wraps
     * the whole flow in Filament's outer transaction — the dispatch
     * defers until that outer commit. But in the ViewInvoice "Submit
     * to myDATA" path, there's no outer transaction, so the dispatch
     * runs synchronously here. Either way, persistResponse() has
     * already committed its own inner transaction by this point, so
     * the invoice + mark row are durable. This is correct behaviour,
     * not a defense — it's why we use afterCommit defensively even
     * though it's a no-op in the common case.
     */
    private function dispatchAutoEmailIfEnabled(Invoice $invoice): void
    {
        if (! ($invoice->company?->auto_email_on_mydata_accept ?? false)) {
            return;
        }

        $invoiceId = $invoice->getKey();
        DB::afterCommit(function () use ($invoiceId): void {
            try {
                $fresh = Invoice::query()->whereKey($invoiceId)->first();
                if ($fresh) {
                    SendInvoiceEmail::dispatch($fresh);
                }
            } catch (Throwable $e) {
                Log::warning('SendInvoiceEmail auto-dispatch failed (filing succeeded)', [
                    'invoice_id' => $invoiceId,
                    'error' => $e->getMessage(),
                ]);
            }
        });
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

        // Stays as string — AADE MARKs are 15+ digit numerics that
        // overflow 32-bit int. CancelInvoice::handle() signature is
        // `string $mark` per firebed's docblock — no need to coerce.
        $markToCancel = (string) $inserts->first()->mark;

        $this->initFirebed();

        // Hold the action so we can extract its raw response XML for
        // the audit row (HasResponseDom trait on the action — NOT
        // (string) on the ResponseDoc, which would crash).
        $action = new CancelInvoice;

        try {
            $action->handle($markToCancel);
        } catch (Throwable $e) {
            $this->logFailure($invoice, 'cancel', $e);
            throw new RuntimeException('myDATA cancellation failed: '.$e->getMessage(), 0, $e);
        }

        $responseXml = $action->getResponseXML() ?? '';

        return DB::transaction(function () use ($invoice, $responseXml, $reason, $markToCancel) {
            $mark = MyDataMark::create([
                'company_id' => $invoice->company_id,
                'invoice_id' => $invoice->id,
                'mark' => $markToCancel,
                'mydata_action' => 'CANCEL',
                'request' => $reason !== '' ? "Cancel reason: {$reason}" : null,
                'response' => $responseXml,
                'mark_date' => now()->toDateString(),
                'mark_time' => now()->toTimeString(),
            ]);

            // MARK_AI0 trigger replacement: flip the invoice's
            // mirror columns to reflect cancellation. The MARK is
            // preserved for audit but state becomes CANCELLED. Sync the
            // local status here too (single choke-point).
            $invoice->forceFill([
                'mydata_state' => 'CANCELLED',
                'local_status' => 'cancelled',
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
            //
            // Format gotchas (per firebed's docblock at
            // vendor/firebed/aade-mydata/src/Http/MyDataGetRequest.php:43):
            //   - $mark is a NON-NULLABLE string — pass '', not null,
            //     else TypeError misclassified as transport failure
            //   - dateFrom / dateTo are 'dd/MM/yyyy', NOT Y-m-d. AADE
            //     rejects the wrong format with a 400 that surfaces as
            //     "myDATA unreachable" to the operator (misleading —
            //     they'd think creds are wrong).
            $action = new RequestTransmittedDocs;
            $action->handle(
                '',
                now()->subDay()->format('d/m/Y'),
                now()->format('d/m/Y'),
            );

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
                .'has no mydata_type set. Configure on the InvoiceType resource.'
            );

        $vatBreakdown = InvoiceVatBreakdown::for($invoice);

        $issuer = (new Issuer)
            ->setVatNumber($this->tenant->afm ?? throw new RuntimeException('Issuer company has no AFM'))
            ->setCountry(CountryCode::GR)
            ->setBranch(0);

        $counterpart = $this->buildCounterpart($invoice, $type);

        $header = (new InvoiceHeader)
            ->setSeries($invoice->invoiceType->code)
            ->setAa((string) $invoice->code)
            ->setIssueDate(Carbon::parse($invoice->issued_at)->toDateString())
            ->setInvoiceType($type)
            ->setCurrency(CurrencyCode::EUR);

        // Credit note: correlate to the original invoice's MARK so AADE
        // links the credit to the document it reverses. (int) is safe on
        // 64-bit PHP — AADE MARKs are ~15 digits, well under PHP_INT_MAX.
        //
        // BUT only for CORRELATED credit types (5.1). For NON-correlated
        // types (5.2) AADE FORBIDS <correlatedInvoices> and rejects the
        // filing — so we must not send it even though we have an original.
        // (myip's ΠΙΣ historically maps to 5.2; sandbox validated 5.1.)
        if ($invoice->credited_invoice_id !== null
            && ! Codes::isNonCorrelatedCreditType((string) $invoice->invoiceType?->mydata_type)) {
            $header->addCorrelatedInvoice((int) $this->originalInsertMark($invoice));
        }

        // Income classification (E3_561_xxx + categoryN_x) comes from the
        // InvoiceType config. AADE requires it for income documents at the
        // per-line level AND aggregated on the summary — verified against
        // an imported legacy MARK request that AADE accepted (it carried
        // the classification at BOTH levels). One class per invoice type,
        // so the per-line amount is just the line net.
        $incomeClass = $invoice->invoiceType?->mydata_income_class;
        $incomeCat = $invoice->invoiceType?->mydata_income_class_category;

        $details = [];
        $lineNo = 1;
        foreach ($invoice->lines as $line) {
            // No setQuantity: AADE rejects a per-line <quantity> for the
            // service invoice types we file ("[205] Quantity Per Line is
            // forbidden for this invoice type"). The legacy accepted
            // payload never sent it. (Goods types that DO take quantity
            // would reinstate it conditionally — follow-up.)
            $detail = (new InvoiceDetails)
                ->setLineNumber($lineNo++)
                ->setNetValue((float) $line->net_price)
                ->setVatCategory($this->vatCategoryFor((float) $line->vat_percent))
                ->setVatAmount(round((float) $line->gross_price - (float) $line->net_price, 2));

            if ($incomeClass && $incomeCat) {
                $detail->addIncomeClassification($incomeClass, $incomeCat, (float) $line->net_price);
            }

            $details[] = $detail;
        }

        // NOTE: deliberately NO <taxesTotals>. In myDATA the
        // taxesTotals/taxes taxType enum is 1=Withholding, 2=Fees,
        // 3=OtherTaxes, 4=StampDuty, 5=Deductions — VAT is NOT among them
        // (it lives per-line via vatCategory/vatAmount and in the summary
        // totalVatAmount). The legacy accepted payload carries no
        // taxesTotals at all. The previous code stuffed VAT into
        // taxType=1, which told AADE there was a withholding tax that
        // didn't match totalWithheldAmount → "[226] withheld sum
        // mismatch". Emit taxesTotals only when real non-VAT taxes are
        // modelled (follow-up: withholding/fees support).

        // AADE's InvoiceSummary XSD requires the intermediate tax-total
        // elements between totalVatAmount and totalGrossValue. Omitting
        // them is rejected with "[101] invalid child element
        // 'totalGrossValue' ... expected 'totalWithheldAmount'". The
        // legacy app sent them as 0.00 (verified against an imported
        // legacy MARK request). Withheld comes from the invoice if set.
        $summary = (new InvoiceSummary)
            ->setTotalNetValue($vatBreakdown->totalNet())
            ->setTotalVatAmount($vatBreakdown->totalVat())
            ->setTotalWithheldAmount((float) ($invoice->withhold_amount ?? 0))
            ->setTotalFeesAmount(0.0)
            ->setTotalStampDutyAmount(0.0)
            ->setTotalOtherTaxesAmount(0.0)
            ->setTotalDeductionsAmount(0.0)
            ->setTotalGrossValue($vatBreakdown->totalGross());

        // Summary-level income classification = aggregate of the per-line
        // classifications (single class per invoice type → total net).
        if ($incomeClass && $incomeCat) {
            $summary->addIncomeClassification($incomeClass, $incomeCat, $vatBreakdown->totalNet());
        }

        $aade = (new AadeInvoice)
            ->setIssuer($issuer)
            ->setInvoiceHeader($header)
            ->setInvoiceDetails($details)
            ->setInvoiceSummary($summary)
            // paymentMethods is mandatory for the invoice types we file
            // ("[204] Payment Methods is mandatory"). The amount must
            // equal the gross total (the legacy payload sent a single
            // detail with type + gross amount). Type defaults to 3
            // (Μετρητά / cash) until per-tenant payment-method → myDATA
            // type mapping is modelled (follow-up).
            ->addPaymentMethod(
                (new PaymentMethodDetail)
                    ->setType($this->paymentMethodTypeFor($invoice))
                    ->setAmount($vatBreakdown->totalGross())
            );

        if ($counterpart) {
            $aade->setCounterpart($counterpart);
        }

        // Deliberately NO client-supplied <uid>: AADE rejects it with
        // "[273] uid is not allowed. It is generated/provided by myDATA".
        // The legacy accepted payload sent no uid. AADE derives its own
        // deterministic uid (from VAT + date + branch + type + series +
        // AA) and uses THAT for resubmission dedup, so retry-idempotency
        // still holds server-side without us sending guessUid().

        return $aade;
    }

    /**
     * myDATA paymentMethods/type for an invoice. Defaults to 3 (Μετρητά
     * / cash) — a safe, always-accepted value — until we model a
     * per-payment-method → myDATA-type mapping on the PaymentMethod
     * lookup. The amount on the detail is the invoice gross.
     */
    private function paymentMethodTypeFor(Invoice $invoice): int
    {
        return 3;
    }

    /**
     * The original invoice's INSERT MARK, for correlating a credit note.
     * Mirrors cancel()'s "read MARK from the audit history, not the
     * mirror column" reasoning. Refuses if the original was never filed
     * (can't correlate a credit to an unfiled document).
     */
    private function originalInsertMark(Invoice $creditNote): string
    {
        $original = Invoice::query()->whereKey($creditNote->credited_invoice_id)->first();
        if (! $original) {
            throw new RuntimeException(
                "Credit note {$creditNote->invcode} references a missing original invoice."
            );
        }

        $mark = MyDataMark::query()
            ->where('invoice_id', $original->id)
            ->where('mydata_action', 'INSERT')
            ->whereNotNull('mark')
            ->orderByDesc('id')
            ->value('mark');

        if (! $mark) {
            throw new RuntimeException(
                "Cannot file credit note for invoice {$original->invcode} — the original has no "
                .'INSERT MARK on file (never submitted to myDATA). File the original first.'
            );
        }

        return (string) $mark;
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

        // Country: prefer the invoice snapshot (the legally-frozen
        // value at issue time); fall back to live customer country,
        // then 'GR'. Normalise to ISO-3166-1 alpha-2 — AADE rejects
        // anything else, including spelled-out names ("Greece").
        $country = $this->normaliseCountryCode($invoice->country ?: $customer->country ?: 'GR');

        $counterpart = (new Counterpart)
            ->setVatNumber($customer->afm)
            ->setCountry($country)
            ->setBranch(0);

        // AADE rule (vendor/firebed/aade-mydata/src/Models/Party.php
        // docblocks): `name` and `address` are FORBIDDEN for GR
        // counterparts and REQUIRED for non-GR. Missing them on a
        // foreign Counterpart causes AADE 4xx with an opaque message.
        if ($country !== 'GR') {
            $counterpart->setName(
                $customer->name
                ?? throw new RuntimeException(
                    "Foreign counterpart on invoice {$invoice->invcode} requires customer name (AADE rule)."
                )
            );
            $counterpart->setAddress(
                (new Address)
                    ->setStreet($invoice->address1 ?: ($customer->address1 ?: 'Unknown'))
                    ->setCity($invoice->city ?: ($customer->city ?: 'Unknown'))
                    ->setPostalCode($invoice->postcode ?: ($customer->postcode ?: '00000'))
            );
        }

        return $counterpart;
    }

    /**
     * Normalise a free-text country string to ISO-3166-1 alpha-2.
     * Real-world data is messy: operators type "Greece", "Ελλάδα",
     * "Hellas", "GR", "GRC" — AADE only accepts the 2-letter code.
     * Defensive: throw on unrecognised input rather than send
     * gibberish that AADE rejects opaquely. Keep this list focused on
     * the countries we actually have tenants/customers in; add cases
     * as needed.
     */
    private function normaliseCountryCode(string $raw): string
    {
        $trimmed = trim(mb_strtoupper($raw));
        // Already in alpha-2 shape
        if (strlen($trimmed) === 2 && ctype_alpha($trimmed)) {
            return $trimmed;
        }

        return match ($trimmed) {
            'GREECE', 'HELLAS', 'ΕΛΛΑΔΑ', 'ΕΛΛΆΔΑ', 'GRC' => 'GR',
            'ESTONIA', 'EESTI', 'EST' => 'EE',
            'CYPRUS', 'ΚΥΠΡΟΣ', 'CYP' => 'CY',
            'GERMANY', 'DEUTSCHLAND', 'ΓΕΡΜΑΝΙΑ', 'DEU' => 'DE',
            default => throw new RuntimeException(
                "Cannot normalise country '{$raw}' to ISO-3166-1 alpha-2. ".
                'Update the customer/invoice country to a 2-letter code, '.
                'or extend MyDataSubmitter::normaliseCountryCode().'
            ),
        };
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
        return (new InvoicesDocWriter)->asXml(
            new InvoicesDoc([$payload])
        );
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
        string $responseXml,
    ): MyDataMark {
        // ResponseDoc extends TypeArray which is iterable and exposes
        // first(). It does NOT have a getResponses() method (the
        // earlier code's invocation of that would have crashed every
        // single successful submit). Iterate properly.
        /** @var Response|null $firstResponse */
        $firstResponse = $response->first();

        if ($firstResponse === null || $firstResponse->getStatusCode() !== 'Success') {
            $errors = $firstResponse ? $this->describeResponseErrors($firstResponse) : 'no response';
            throw new RuntimeException("myDATA rejected the submission: {$errors}");
        }

        $mark = (string) $firstResponse->getInvoiceMark();
        $qrUrl = $firstResponse->getQrUrl();

        // Idempotent INSERT: if a mydata_marks row already exists for
        // this invoice+mark, return it instead of writing a duplicate.
        // Defends against the rare AADE-success / local-DB-fail retry
        // scenario where UID dedup at AADE returns the original MARK
        // on the operator's second attempt — we'd otherwise pile up
        // duplicate audit rows for the same logical filing.
        $existing = MyDataMark::query()
            ->where('invoice_id', $invoice->id)
            ->where('mark', $mark)
            ->where('mydata_action', 'INSERT')
            ->first();
        if ($existing) {
            Log::info('myDATA submit: idempotent — MARK already recorded locally', [
                'invoice_id' => $invoice->id,
                'mark' => $mark,
            ]);

            return $existing;
        }

        return DB::transaction(function () use ($invoice, $payload, $xml, $responseXml, $mark, $qrUrl) {
            $audit = MyDataMark::create([
                'company_id' => $invoice->company_id,
                'invoice_id' => $invoice->id,
                'mark' => $mark,
                'mydata_action' => 'INSERT',
                'invoice_url' => $qrUrl,
                'request' => $xml,
                // Raw response XML from firebed's HasResponseDom trait —
                // NOT (string) $response (ResponseDoc has no __toString,
                // would crash). The action's getResponseXML() is the
                // legally-required verbatim record.
                'response' => $responseXml,
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
                // A filed invoice is a live document. Promote a draft to
                // 'active' HERE (the single submit choke-point) so every
                // path — ViewInvoice submit, create-and-submit, credit-note
                // submit, bulk submit — stays in sync. (Leaves 'cancelled'
                // alone: a cancelled-then-filed doc surfaces in the
                // reconciliation worklist, not silently flipped active.)
                'local_status' => $invoice->local_status === 'draft' ? 'active' : $invoice->local_status,
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
        // getErrors() returns a firebed Errors object (TypeArray —
        // iterable, NOT a plain array), or null. Iterate it; array_map()
        // over the object TypeErrors and masks the real AADE rejection.
        $errs = $response->getErrors();
        if ($errs === null) {
            return $response->getStatusCode() ?? 'unknown';
        }
        $messages = [];
        foreach ($errs as $e) {
            $code = method_exists($e, 'getCode') ? $e->getCode() : null;
            $msg = method_exists($e, 'getMessage') ? $e->getMessage() : (string) $e;
            $messages[] = $code ? "[{$code}] {$msg}" : $msg;
        }

        return implode('; ', $messages) ?: ($response->getStatusCode() ?? 'unknown');
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
