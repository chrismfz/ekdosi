<?php

namespace App\Services\Delivery;

use App\Enums\MyDataMode;
use App\Exceptions\EInvoice\ProviderTransportException;
use App\Models\Company;
use App\Models\DeliveryMark;
use App\Models\DeliveryNote;
use App\Services\EInvoice\ProviderTransportRegistry;
use App\Services\Stock\StockService;
use App\Support\EInvoice\ProviderCredentials;
use App\Support\EInvoice\ProviderResult;
use App\Support\MyData\DeliveryCodes;
use Carbon\Carbon;
use Firebed\AadeMyData\Enums\CountryCode;
use Firebed\AadeMyData\Enums\IncomeClassificationCategory;
use Firebed\AadeMyData\Enums\MovePurpose;
use Firebed\AadeMyData\Exceptions\MyDataAuthenticationException;
use Firebed\AadeMyData\Exceptions\MyDataConnectionException;
use Firebed\AadeMyData\Exceptions\MyDataException;
use Firebed\AadeMyData\Exceptions\MyDataTimeoutException;
use Firebed\AadeMyData\Http\MyDataRequest;
use Firebed\AadeMyData\Http\SendInvoices;
use Firebed\AadeMyData\Models\Address;
use Firebed\AadeMyData\Models\Counterpart;
use Firebed\AadeMyData\Models\Invoice as AadeInvoice;
use Firebed\AadeMyData\Models\InvoiceDetails;
use Firebed\AadeMyData\Models\InvoiceHeader;
use Firebed\AadeMyData\Models\InvoicesDoc;
use Firebed\AadeMyData\Models\InvoiceSummary;
use Firebed\AadeMyData\Models\Issuer;
use Firebed\AadeMyData\Models\OtherDeliveryNoteHeader;
use Firebed\AadeMyData\Models\Response;
use Firebed\AadeMyData\Models\ResponseDoc;
use Firebed\AadeMyData\Xml\InvoicesDocWriter;
use GuzzleHttp\Handler\MockHandler;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

/**
 * myDATA submitter for a Παραστατικό Διακίνησης / Δελτίο Αποστολής (e-transport).
 *
 * A delivery note is VALUE-LESS — it carries goods movement, not money — but it
 * is filed via the SAME SendInvoices endpoint and the SAME firebed AadeInvoice
 * payload model as a real invoice, with a 9.x invoice type, delivery header
 * fields (isDeliveryNote / movePurpose / dispatch* / vehicleNumber /
 * otherDeliveryNoteHeader with loading+delivery addresses) and value-less lines.
 *
 * Deliberately SELF-CONTAINED rather than reusing MyDataSubmitter: the proven
 * invoice path stays untouched, and the delivery payload shape differs enough
 * (zero values, vatCategory=8, delivery header, no payment methods, no Ε3 income
 * classification — but the mandatory «category3 = Διακίνηση» characterization)
 * that sharing would couple two evolving concerns.
 * The few genuinely-shared idioms (per-tenant initFirebed, GR-counterpart rule,
 * country normalisation, persistResponse audit+cache) are duplicated privately.
 *
 * The 9.x value-less line/summary shape is grounded in the firebed reference
 * payload vendor/firebed/aade-mydata/stubs/request-doc-with-delivery-lifecycle.xml
 * (a real 9.3 document): each line carries quantity + measurementUnit +
 * netValue=0 + vatCategory=8 (Εγγραφές χωρίς ΦΠΑ) + vatAmount=0, and the summary
 * is all-zero totals plus the five zero tax-total fields. InvoiceType::
 * supportsUnitOfMeasurement() (vendor/.../Enums/InvoiceType.php) lists 9.3, so
 * quantity + measurementUnit are valid (unlike the service-invoice path where
 * [205] forbids per-line quantity).
 *
 * ✅ SANDBOX-VALIDATED (2026-06-10) — the full ΔΑ lifecycle (issue/register/confirm)
 * was round-tripped against the AADE sandbox and accepted with real MARKs (see the
 * «Sandbox round 2» note in CLAUDE.md). The value-less 9.3 shape (quantity +
 * measurementUnit) is confirmed live; no payload changes were needed.
 */
class DeliveryNoteSubmitter
{
    public function __construct(
        private readonly Company $tenant,
        /**
         * Optional Guzzle MockHandler for tests — mirrors MyDataSubmitter.
         * Production never passes this.
         */
        private readonly ?MockHandler $mockHandler = null,
    ) {}

    /**
     * Build the firebed AadeInvoice payload for this delivery note. PUBLIC so a
     * test can assert the built object without any network call.
     */
    public function buildAadeDeliveryNote(DeliveryNote $note): AadeInvoice
    {
        $note->loadMissing(['lines', 'deliveryType', 'customer']);

        // ΑΑ guard — mirrors MyDataSubmitter. A code of 0 means the numberer
        // never ran (draft / broken save); filing <aa>0</aa> is illegal.
        if (! $note->code || (int) $note->code < 1) {
            throw new RuntimeException(
                "Delivery note {$note->invcode} has no ΑΑ number (code=".($note->code ?? 'null').'). '.
                'Allocate the number before submission.'
            );
        }

        $type = $note->mydata_type
            ?? $note->deliveryType?->mydata_type
            ?? throw new RuntimeException(
                "Delivery note {$note->invcode} cannot be submitted — no mydata_type set "
                .'(neither on the note nor its delivery type). Configure a 9.x type.'
            );

        $movePurpose = (int) ($note->move_purpose ?? 0);
        if ($movePurpose < 1) {
            throw new RuntimeException(
                "Delivery note {$note->invcode} has no move purpose (σκοπός διακίνησης, §8.14). "
                .'Set delivery_notes.move_purpose before submission.'
            );
        }
        if (! DeliveryCodes::isMovePurposeAllowed($movePurpose)) {
            throw new RuntimeException(
                "Delivery note {$note->invcode} has move purpose {$movePurpose}, which AADE no longer "
                .'accepts for transmission (§8.14: 6/15/16/17/18). Use 8 (ενδοδιακίνηση) or 19 (λοιπές) instead.'
            );
        }

        // Unlike a monetary invoice (where [219]/[220] FORBID issuer name/address
        // for a GR party), a 9.x delivery note REQUIRES the issuer's full
        // identification — AADE rejects with [204] «issuer Name/address is
        // mandatory» otherwise (sandbox-proven 2026-06-03; matches the firebed
        // 9.3 reference payload which carries issuer name + address).
        $issuer = (new Issuer)
            ->setVatNumber($this->tenant->afm ?? throw new RuntimeException('Issuer company has no AFM'))
            ->setCountry(CountryCode::GR)
            ->setBranch(0)
            ->setName($this->tenant->name ?? throw new RuntimeException('Issuer company has no name'))
            ->setAddress($this->buildTenantAddress());

        // NO <currency> and NO <isDeliveryNote> for a 9.x delivery note — AADE
        // rejects both with [205] «forbidden for this invoice type» (the goods
        // movement IS the document; the 9.x type already marks it as a δελτίο).
        // <thirdPartyCollection> is sent ONLY when true ([214] forbids false).
        $header = (new InvoiceHeader)
            ->setSeries($note->deliveryType?->code ?? '0')
            ->setAa((string) $note->code)
            ->setIssueDate(Carbon::parse($note->issued_at)->toDateString())
            ->setInvoiceType($type)
            ->setMovePurpose(MovePurpose::from($movePurpose))
            ->setOtherDeliveryNoteHeader($this->buildDeliveryHeader($note));

        if ($note->third_party_collection) {
            $header->setThirdPartyCollection(true);
        }

        // movePurpose=19 (Λοιπές Διακινήσεις) requires the free-text title.
        if ($movePurpose === 19) {
            $title = trim((string) $note->other_move_purpose_title);
            if ($title === '') {
                throw new RuntimeException(
                    "Delivery note {$note->invcode} uses move purpose 19 (Λοιπές Διακινήσεις) but has no "
                    .'other_move_purpose_title. AADE requires the title for this purpose.'
                );
            }
            $header->setOtherMovePurposeTitle($title);
        }

        // Planned dispatch date/time (optional).
        if ($note->dispatch_at) {
            $dispatch = Carbon::parse($note->dispatch_at);
            $header->setDispatchDate($dispatch->toDateString());
            $header->setDispatchTime($dispatch->format('H:i:s'));
        }

        if (! empty($note->vehicle_number)) {
            $header->setVehicleNumber($note->vehicle_number);
        }

        $details = [];
        $lineNo = 1;
        foreach ($note->lines as $line) {
            // Value-less line shape per the 9.3 reference payload:
            //   quantity + measurementUnit + netValue=0 + vatCategory=8 + vatAmount=0.
            // measurementUnit is a coded LEGAL quantity meaning (§8.13, 1–7), so a
            // missing/out-of-range value is a data error to surface — NOT to repair
            // by silently filing pieces (that changes what the movement line means).
            // A new UI line starts at 1 in the form; this boundary never defaults.
            // MYD-016.
            $unit = $line->measurement_unit;   // model casts to ?int
            if ($unit === null || $unit < 1 || $unit > 7) {
                throw new RuntimeException(
                    "Γραμμή του δελτίου {$note->invcode} έχει μη έγκυρη μονάδα μέτρησης "
                    .'(υποστηριζόμενες §8.13: 1–6). Διορθώστε την πριν την υποβολή.'
                );
            }
            // Unit 7 (Τεμάχια_Λοιπές Περιπτώσεις) needs otherMeasurementUnitQuantity/
            // Title (§8.13 note 9, mandatory) which we do not model/emit yet, so a
            // 7 line cannot be filed correctly — fail loudly instead of malformed.
            if ($unit === 7) {
                throw new RuntimeException(
                    "Η μονάδα μέτρησης 7 (Τεμάχια_Λοιπές Περιπτώσεις) στο δελτίο {$note->invcode} "
                    .'δεν υποστηρίζεται ακόμη (απαιτεί otherMeasurementUnitQuantity/Title) — '
                    .'επιλέξτε 1–6 ή χωρίστε τη γραμμή.'
                );
            }

            $detail = (new InvoiceDetails)
                ->setLineNumber($lineNo++)
                ->setQuantity((float) ($line->qty ?? 0))
                ->setMeasurementUnit((string) $unit)
                ->setNetValue(0.0)
                ->setVatCategory('8')   // 8 = Εγγραφές χωρίς ΦΠΑ (no VAT)
                ->setVatAmount(0.0);

            if (! empty($line->product_descr)) {
                $detail->setItemDescr((string) $line->product_descr);
            }

            // «Χαρακτηρισμός Συναλλαγών 3 = Διακίνηση» — MANDATORY on a delivery
            // note per Α.1123/2024 Άρθρο 5 §5.2.2 (and the firebed 9.3 reference
            // payload). category3 / amount 0 / NO classificationType (there is
            // no Ε3 income classification on a value-less δελτίο, §5.4.2).
            $detail->addIncomeClassification(null, IncomeClassificationCategory::CATEGORY_3, 0.0);

            $details[] = $detail;
        }

        if ($details === []) {
            throw new RuntimeException(
                "Delivery note {$note->invcode} has no lines — cannot file a delivery note with zero items."
            );
        }

        // Value-less summary: all zeros, including the five tax-total fields the
        // InvoiceSummary XSD requires between totalVatAmount and totalGrossValue
        // ([101]) — exactly as the reference 9.3 payload carries them.
        $summary = (new InvoiceSummary)
            ->setTotalNetValue(0.0)
            ->setTotalVatAmount(0.0)
            ->setTotalWithheldAmount(0.0)
            ->setTotalFeesAmount(0.0)
            ->setTotalStampDutyAmount(0.0)
            ->setTotalOtherTaxesAmount(0.0)
            ->setTotalDeductionsAmount(0.0)
            ->setTotalGrossValue(0.0);

        // Same «3 = Διακίνηση» characterization aggregated at the summary level
        // (the 9.3 reference payload carries it on both line and summary).
        $summary->addIncomeClassification(null, IncomeClassificationCategory::CATEGORY_3, 0.0);

        $aade = (new AadeInvoice)
            ->setIssuer($issuer)
            ->setInvoiceHeader($header)
            ->setInvoiceDetails($details)
            ->setInvoiceSummary($summary);

        // No paymentMethods: a value-less delivery note has nothing to pay
        // ([204]'s "mandatory" applies to monetary invoice types, not 9.x).

        $aade->setCounterpart($this->buildCounterpart($note));

        return $aade;
    }

    /** Build the XML preview (mirrors MyDataSubmitter::payloadToXml). */
    public function previewXml(DeliveryNote $note): string
    {
        return $this->payloadToXml($this->buildAadeDeliveryNote($note));
    }

    /**
     * File the delivery note through the tenant's electronic channel (direct
     * myDATA or ΥΠΑΗΕΣ provider) and persist the resulting MARK + delivery_marks
     * audit row + the note's mydata/delivery cache.
     */
    public function submit(DeliveryNote $note): DeliveryMark
    {
        if ($note->mydata_state === 'VALID') {
            throw new RuntimeException(
                "Delivery note {$note->invcode} was already filed at myDATA (MARK "
                .($note->mydata_mark ?: '?').'). Cancel and re-issue if a correction is needed.'
            );
        }
        if ($note->mydata_state === 'CANCELLED') {
            throw new RuntimeException(
                "Delivery note {$note->invcode} was previously filed and CANCELLED at myDATA. "
                .'Issue a fresh delivery note instead of resubmitting.'
            );
        }
        if ($note->mydata_state !== null && $note->mydata_state !== '') {
            throw new RuntimeException(
                "Delivery note {$note->invcode} has an unrecognised mydata_state='{$note->mydata_state}'. "
                .'Refusing to submit — investigate before retrying.'
            );
        }

        $payload = $this->buildAadeDeliveryNote($note);
        $xml = $this->payloadToXml($payload);

        if ($this->tenant->isLiveProviderTenant()) {
            return $this->submitViaProvider($note, $xml);
        }

        $this->initFirebed();

        $action = new SendInvoices;

        try {
            $response = $action->handle($payload);
        } catch (MyDataAuthenticationException $e) {
            $this->logFailure($note, 'auth', $e);
            throw new RuntimeException('myDATA rejected credentials. Check Company → myDATA submission tab.', 0, $e);
        } catch (MyDataTimeoutException|MyDataConnectionException $e) {
            $this->logFailure($note, 'transport', $e);
            throw new RuntimeException('myDATA endpoint unreachable. Try again later.', 0, $e);
        } catch (MyDataException $e) {
            $this->logFailure($note, 'protocol', $e);
            throw new RuntimeException('myDATA delivery-note submission failed: '.$e->getMessage(), 0, $e);
        } catch (Throwable $e) {
            $this->logFailure($note, 'other', $e);
            throw new RuntimeException('myDATA delivery-note submission failed unexpectedly.', 0, $e);
        }

        $responseXml = $action->getResponseXML() ?? '';

        return $this->persistResponse($note, $xml, $response, $responseXml);
    }

    /** Submit the same canonical 9.x AADE XML through the tenant's ΥΠΑΗΕΣ provider. */
    private function submitViaProvider(DeliveryNote $note, string $xml): DeliveryMark
    {
        $transport = app(ProviderTransportRegistry::class)->for((string) $this->tenant->einvoice_provider_key);
        $credentials = ProviderCredentials::fromCompany($this->tenant);

        try {
            $result = $transport->sendDelivery($note, $xml, $credentials);
        } catch (Throwable $e) {
            $attempted = ($e instanceof ProviderTransportException && $e->attemptedPayload !== null)
                ? $e->attemptedPayload
                : $xml;
            $this->recordProviderFailure($note, $transport->key(), $attempted, $e->getMessage());

            throw new RuntimeException('E-invoice provider delivery-note submission failed: '.$e->getMessage(), 0, $e);
        }

        if (! $result->success) {
            $this->recordProviderRejection($note, $transport->key(), $xml, $result);
            throw new DeliveryNoteRejected(
                'E-invoice provider rejected the delivery note: '.$result->errorMessage(),
                $xml,
                $result->raw ?? ''
            );
        }

        return $this->persistProviderSuccess($note, $transport->key(), $xml, $result);
    }

    private function recordProviderFailure(DeliveryNote $note, string $providerKey, string $requestXml, string $error): void
    {
        try {
            DB::transaction(fn () => DeliveryMark::create($this->deliveryMarkPayload([
                'company_id' => $note->company_id,
                'delivery_note_id' => $note->id,
                'mark' => null,
                'mydata_action' => 'PROVIDER_FAILED',
                'provider_key' => $providerKey,
                'request' => $requestXml,
                'response' => 'Transport error: '.$error,
                'mark_date' => now()->toDateString(),
                'mark_time' => now()->toTimeString(),
            ])));
        } catch (Throwable $e) {
            Log::warning('Provider delivery: failed to persist failure audit row', [
                'delivery_note_id' => $note->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function recordProviderRejection(DeliveryNote $note, string $providerKey, string $requestXml, ProviderResult $result): void
    {
        try {
            DB::transaction(fn () => DeliveryMark::create($this->deliveryMarkPayload([
                'company_id' => $note->company_id,
                'delivery_note_id' => $note->id,
                'mark' => null,
                'mydata_action' => 'PROVIDER_REJECTED',
                'provider_key' => $providerKey,
                'request' => $result->requestPayload ?? $requestXml,
                'response' => $result->raw,
                'mark_date' => now()->toDateString(),
                'mark_time' => now()->toTimeString(),
            ])));
        } catch (Throwable $e) {
            Log::warning('Provider delivery: failed to persist rejection audit row', [
                'delivery_note_id' => $note->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function persistProviderSuccess(DeliveryNote $note, string $providerKey, string $xml, ProviderResult $result): DeliveryMark
    {
        $mark = (string) $result->mark;
        if ($mark === '') {
            throw new RuntimeException("E-invoice provider reported success but returned no MARK for delivery note {$note->invcode}.");
        }

        $existing = DeliveryMark::query()
            ->where('delivery_note_id', $note->id)
            ->where('mark', $mark)
            ->where('mydata_action', 'PROVIDER_INSERT')
            ->first();
        if ($existing) {
            return $existing;
        }

        $audit = DB::transaction(function () use ($note, $providerKey, $xml, $result, $mark) {
            $row = DeliveryMark::create($this->deliveryMarkPayload([
                'company_id' => $note->company_id,
                'delivery_note_id' => $note->id,
                'mark' => $mark,
                'mydata_action' => 'PROVIDER_INSERT',
                'provider_key' => $providerKey,
                'authentication_code' => $result->authenticationCode,
                'provider_delivery_state' => $result->deliveryState,
                'invoice_url' => $result->qrUrl,
                'request' => $result->requestPayload ?? $xml,
                'response' => $result->raw,
                'mark_date' => now()->toDateString(),
                'mark_time' => now()->toTimeString(),
            ]));

            $note->forceFill([
                'mydata_sent' => true,
                'mydata_state' => 'VALID',
                'mydata_mark' => $mark,
                'mydata_url' => $result->qrUrl ?? $note->mydata_url,
                'delivery_state' => 'registered',
                'local_status' => $note->local_status === 'draft' ? 'active' : $note->local_status,
            ])->save();

            return $row;
        });

        try {
            app(StockService::class)->recordSaleForDeliveryNote($note);
        } catch (Throwable $e) {
            Log::warning('S2 stock-out after provider delivery-note filing failed (filing succeeded)', [
                'delivery_note_id' => $note->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $audit;
    }

    /**
     * Filter provider audit payloads to columns that exist in the currently-migrated
     * schema. This keeps the request/response forensic row visible during deploys
     * where code reaches the server before the additive provider-column migration.
     * Once migrated, provider_key/authentication_code/provider_delivery_state are
     * kept as normal.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function deliveryMarkPayload(array $payload): array
    {
        static $columns = null;

        $columns ??= array_flip(Schema::getColumnListing('delivery_marks'));

        return array_intersect_key($payload, $columns);
    }

    // ---- internals ------------------------------------------------------

    private function buildDeliveryHeader(DeliveryNote $note): OtherDeliveryNoteHeader
    {
        // Loading + delivery addresses are MANDATORY for 9.x / isDeliveryNote
        // (spec + OtherDeliveryNoteHeader docblocks). Hard-fail on a blank one
        // rather than file a fabricated '00000'/'Άγνωστη' into a legally
        // significant e-transport record — symmetric with the ΑΑ / move-purpose
        // / no-lines guards. The D2 form also makes them required; this is the
        // last line of defence so the submitter is never the sole guarantee.
        $this->requireAddress($note->invcode, 'φόρτωσης', $note->loading_street, $note->loading_postcode, $note->loading_city);
        $this->requireAddress($note->invcode, 'παράδοσης', $note->delivery_street, $note->delivery_postcode, $note->delivery_city);

        $loading = (new Address)
            ->setStreet($note->loading_street)
            ->setNumber($note->loading_number ?: '0')
            ->setPostalCode($note->loading_postcode)
            ->setCity($note->loading_city);

        $delivery = (new Address)
            ->setStreet($note->delivery_street)
            ->setNumber($note->delivery_number ?: '0')
            ->setPostalCode($note->delivery_postcode)
            ->setCity($note->delivery_city);

        $header = (new OtherDeliveryNoteHeader)
            ->setLoadingAddress($loading)
            ->setDeliveryAddress($delivery);

        if ($note->start_shipping_branch !== null) {
            $header->setStartShippingBranch((int) $note->start_shipping_branch);
        }
        if ($note->complete_shipping_branch !== null) {
            $header->setCompleteShippingBranch((int) $note->complete_shipping_branch);
        }

        return $header;
    }

    /** The tenant's registered seat as an Address (mandatory on the issuer for 9.x). */
    private function buildTenantAddress(): Address
    {
        return (new Address)
            ->setStreet($this->tenant->address ?: 'Έδρα')
            ->setNumber((string) ($this->tenant->address_number ?: '0'))
            ->setPostalCode($this->tenant->postcode ?: '00000')
            ->setCity($this->tenant->city ?: 'Unknown');
    }

    /** Throw unless a mandatory delivery address has street + postcode + city. */
    private function requireAddress(string $invcode, string $which, ?string $street, ?string $postcode, ?string $city): void
    {
        if (trim((string) $street) === '' || trim((string) $postcode) === '' || trim((string) $city) === '') {
            throw new RuntimeException(
                "Delivery note {$invcode}: η διεύθυνση {$which} (οδός + Τ.Κ. + πόλη) είναι υποχρεωτική για δελτίο αποστολής (9.x)."
            );
        }
    }

    /**
     * The delivery recipient as a Counterpart. Unlike the monetary-invoice GR
     * rule (where [219]/[220] FORBID a GR counterpart's name/address), a 9.x
     * delivery note REQUIRES counterpart name + address for ANY country — AADE
     * rejects with [204] «Counterpart Name/address is mandatory» otherwise
     * (sandbox-proven 2026-06-03; the firebed 9.3 reference carries them even
     * for a GR counterpart). The recipient ΑΦΜ is NEVER omitted: for an
     * ενδοδιακίνηση (own-branch move, no external recipient) the law fills it
     * with nine zeros «000000000» (Α.1123/2024 Παράρτημα ΙΙ §3) and the
     * recipient identity falls back to the issuer's own (it IS the issuer).
     */
    private function buildCounterpart(DeliveryNote $note): Counterpart
    {
        $afm = $note->recipient_afm ?: $note->customer?->afm ?: '000000000';

        $rawCountry = $note->customer?->country ?: 'GR';
        $country = $this->normaliseCountryCode($rawCountry);

        $name = $note->recipient_name
            ?: $note->customer?->name
            ?: $this->tenant->name   // ενδοδιακίνηση — recipient is the issuer
            ?: throw new RuntimeException(
                "Delivery note {$note->invcode} has no recipient name and the issuer company has none either."
            );

        // The delivery address is mandatory on the note (buildDeliveryHeader
        // already guards it), so it's the natural counterpart address.
        $address = (new Address)
            ->setStreet($note->delivery_street ?: ($note->customer?->address1 ?: 'Unknown'))
            ->setNumber($note->delivery_number ?: '0')
            ->setPostalCode($note->delivery_postcode ?: ($note->customer?->postcode ?: '00000'))
            ->setCity($note->delivery_city ?: ($note->customer?->city ?: 'Unknown'));

        return (new Counterpart)
            ->setVatNumber($afm)
            ->setCountry($country)
            ->setBranch(0)
            ->setName($name)
            ->setAddress($address);
    }

    /** ISO-3166-1 alpha-2 normalisation — duplicated from MyDataSubmitter. */
    private function normaliseCountryCode(string $raw): string
    {
        $trimmed = trim(mb_strtoupper($raw));
        if (strlen($trimmed) === 2 && ctype_alpha($trimmed)) {
            return $trimmed;
        }

        return match ($trimmed) {
            'GREECE', 'HELLAS', 'ΕΛΛΑΔΑ', 'ΕΛΛΆΔΑ', 'GRC' => 'GR',
            'ESTONIA', 'EESTI', 'EST' => 'EE',
            'CYPRUS', 'ΚΥΠΡΟΣ', 'CYP' => 'CY',
            'GERMANY', 'DEUTSCHLAND', 'ΓΕΡΜΑΝΙΑ', 'DEU' => 'DE',
            default => throw new RuntimeException(
                "Cannot normalise country '{$raw}' to ISO-3166-1 alpha-2 on delivery note. ".
                'Use a 2-letter code, or extend DeliveryNoteSubmitter::normaliseCountryCode().'
            ),
        };
    }

    private function initFirebed(?MyDataMode $environment = null): void
    {
        $mode = $environment ?? $this->tenant->mydata_mode_enum;

        [$aadeId, $subKey] = $this->tenant->mydataCredentials($mode);

        if (empty($aadeId) || empty($subKey)) {
            throw new RuntimeException(
                'myDATA credentials are not configured for this tenant ('.$mode->value.' environment).'
            );
        }

        $env = $mode === MyDataMode::Production ? 'prod' : 'dev';

        MyDataRequest::init($aadeId, $subKey, $env);
        MyDataRequest::setHandler($this->mockHandler);
    }

    private function payloadToXml(AadeInvoice $payload): string
    {
        return (new InvoicesDocWriter)->asXml(new InvoicesDoc([$payload]));
    }

    /**
     * Persist a forensic REJECTED audit row (request + response XML, no MARK)
     * when AADE refuses a delivery note — so the operator sees WHAT was sent and
     * WHY from the δελτίο's «Ιστορικό myDATA». Best-effort: an audit-write
     * failure must never mask the real rejection. Twin of MyDataSubmitter::recordRejection.
     */
    private function recordRejection(DeliveryNote $note, string $requestXml, string $responseXml): void
    {
        try {
            DB::transaction(fn () => DeliveryMark::create([
                'company_id' => $note->company_id,
                'delivery_note_id' => $note->id,
                'mark' => null,
                'mydata_action' => 'REJECTED',
                'request' => $requestXml,
                'response' => $responseXml,
                'mark_date' => now()->toDateString(),
                'mark_time' => now()->toTimeString(),
            ]));
        } catch (Throwable $e) {
            Log::warning('myDATA delivery: failed to persist REJECTED audit row', [
                'delivery_note_id' => $note->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function persistResponse(
        DeliveryNote $note,
        string $xml,
        ResponseDoc $response,
        string $responseXml,
    ): DeliveryMark {
        /** @var Response|null $first */
        $first = $response->first();

        if ($first === null || $first->getStatusCode() !== 'Success') {
            $errors = $first ? $this->describeResponseErrors($first) : 'no response';
            // Persist a forensic REJECTED row so the rejection is visible in the
            // δελτίο's «Ιστορικό myDATA» UI (not only in the CLI report), then
            // carry the XML on the throw. Mirrors MyDataSubmitter::recordRejection.
            $this->recordRejection($note, $xml, $responseXml);
            throw new DeliveryNoteRejected(
                "myDATA rejected the delivery note: {$errors}",
                $xml,
                $responseXml,
            );
        }

        $mark = (string) $first->getInvoiceMark();
        $qrUrl = $first->getQrUrl();

        // Idempotent INSERT: don't write a duplicate audit row for the same
        // note+mark (AADE UID-dedup may return the original MARK on a retry).
        $existing = DeliveryMark::query()
            ->where('delivery_note_id', $note->id)
            ->where('mark', $mark)
            ->where('mydata_action', 'INSERT')
            ->first();
        if ($existing) {
            Log::info('myDATA delivery-note submit: idempotent — MARK already recorded locally', [
                'delivery_note_id' => $note->id,
                'mark' => $mark,
            ]);

            return $existing;
        }

        $audit = DB::transaction(function () use ($note, $xml, $responseXml, $mark, $qrUrl) {
            $row = DeliveryMark::create([
                'company_id' => $note->company_id,
                'delivery_note_id' => $note->id,
                'mark' => $mark,
                'mydata_action' => 'INSERT',
                'invoice_url' => $qrUrl,
                'request' => $xml,
                'response' => $responseXml,
                'mark_date' => now()->toDateString(),
                'mark_time' => now()->toTimeString(),
            ]);

            // Cache columns are GUARDED (not fillable) — written ONLY here via
            // forceFill, alongside the audit row. delivery_state='registered'
            // is the first e-transport lifecycle state (§8.22).
            $note->forceFill([
                'mydata_sent' => true,
                'mydata_state' => 'VALID',
                'mydata_mark' => $mark,
                'mydata_url' => $qrUrl,
                'delivery_state' => 'registered',
                'local_status' => $note->local_status === 'draft' ? 'active' : $note->local_status,
            ])->save();

            return $row;
        });

        // Stock-OUT for a Πώληση δελτίο (S2). No-ops for any other σκοπός and for
        // untracked products; idempotent + whichever-first (skips if the linked
        // invoice already moved). Outside the audit transaction and BEST-EFFORT:
        // the δελτίο is already filed at AADE (VALID), so a stock-write hiccup must
        // never surface as a false "issuance failed" — mirrors recordRejection.
        try {
            app(StockService::class)->recordSaleForDeliveryNote($note);
        } catch (Throwable $e) {
            Log::warning('S2 stock-out after delivery-note filing failed (filing succeeded)', [
                'delivery_note_id' => $note->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $audit;
    }

    private function describeResponseErrors($response): string
    {
        if (! method_exists($response, 'getErrors')) {
            return $response->getStatusCode() ?? 'unknown';
        }
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

    private function logFailure(DeliveryNote $note, string $kind, Throwable $e): void
    {
        Log::warning('myDATA delivery-note submission failure', [
            'company_id' => $this->tenant->getKey(),
            'delivery_note_id' => $note->id,
            'invcode' => $note->invcode,
            'kind' => $kind,
            'exception' => get_class($e),
            'message' => $e->getMessage(),
        ]);
    }
}
