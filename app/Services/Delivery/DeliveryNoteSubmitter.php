<?php

namespace App\Services\Delivery;

use App\Enums\MyDataMode;
use App\Exceptions\EInvoice\ProviderTransportException;
use App\Models\Company;
use App\Models\DeliveryMark;
use App\Models\DeliveryNote;
use App\Services\EInvoice\ProviderTransportRegistry;
use App\Services\MyData\FirebedCredentials;
use App\Services\MyData\SalesReconciler;
use App\Services\Stock\StockService;
use App\Support\EInvoice\ProviderCredentials;
use App\Support\EInvoice\ProviderIssueDateGuard;
use App\Support\EInvoice\ProviderResult;
use App\Support\IsoCountry;
use App\Support\MyData\Codes;
use App\Support\MyData\DeliveryCodes;
use App\Support\Tenancy\TenantCoherence;
use Carbon\Carbon;
use Firebed\AadeMyData\Enums\CountryCode;
use Firebed\AadeMyData\Enums\IncomeClassificationCategory;
use Firebed\AadeMyData\Enums\MovePurpose;
use Firebed\AadeMyData\Exceptions\InvalidResponseException;
use Firebed\AadeMyData\Exceptions\MyDataAuthenticationException;
use Firebed\AadeMyData\Exceptions\MyDataConnectionException;
use Firebed\AadeMyData\Exceptions\MyDataException;
use Firebed\AadeMyData\Exceptions\MyDataTimeoutException;
use Firebed\AadeMyData\Exceptions\RateLimitExceededException;
use Firebed\AadeMyData\Exceptions\TransmissionFailedException;
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
use Illuminate\Support\Facades\Cache;
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
 * persistResponse audit+cache) are duplicated privately. Country normalisation is
 * NOT duplicated — it lives in App\Support\IsoCountry, shared with the monetary
 * invoice payload, so the two can't drift on EL→GR / UK→GB (MYD-011).
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

        // Only 9.3 (απλό Δελτίο Αποστολής) is fileable today (allowlist, MYD-012):
        // 9.1 (συσχετιζόμενο) needs a correlated-MARK payload, 9.2 (συγκεντρωτικό)
        // an aggregation model, and any other/future 9.x is unbuilt — all blocked
        // so a non-UI caller can't file an incomplete note. The picker hides the
        // same set (single source of truth, no drift).
        if (! Codes::isSupportedDeliveryType($type)) {
            throw new RuntimeException(
                "Το δελτίο {$note->invcode} έχει τύπο {$type}, ο οποίος δεν υποστηρίζεται ακόμη "
                .'(υποστηρίζεται μόνο το 9.3 — απλό Δελτίο Αποστολής· το 9.1 συσχετιζόμενο θέλει '
                .'συσχετισμένα MARK, το 9.2 συγκεντρωτικό θέλει μοντέλο σύνοψης). '
                .'Χρησιμοποιήστε 9.3 ή χωρίστε τη διακίνηση.'
            );
        }

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
            // MYD-018: the FROZEN series, never the editable lookup.
            ->setSeries($note->filedSeries() ?? '0')
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
        TenantCoherence::assertDeliveryNote($this->tenant, $note);

        return $this->payloadToXml($this->buildAadeDeliveryNote($note));
    }

    /**
     * File the delivery note through the tenant's electronic channel (direct
     * myDATA or ΥΠΑΗΕΣ provider) and persist the resulting MARK + delivery_marks
     * audit row + the note's mydata/delivery cache.
     */
    public function submit(DeliveryNote $note): DeliveryMark
    {
        // MYD-022: fail closed BEFORE payload construction, audit writes or any
        // outbound request — a tenant mismatch must never reach the wire.
        TenantCoherence::assertDeliveryNote($this->tenant, $note);

        // MYD-021: a 9.x δελτίο is filed through the same AADE channel and is just
        // as legally binding as an invoice, but this path had NONE of the invoice
        // path's protections — no lock, no in-doubt marker, no adopt-or-file. Two
        // concurrent requests (double click, two tabs, an overlapping worker) could
        // each POST the same local note and create TWO AADE documents; AADE does
        // not dedup. Mirror the invoice gate exactly rather than invent a second
        // shape for the same problem.
        //
        // A cache lock, NOT a DB row lock: a row lock must not be held across the
        // AADE HTTP call (the orphan-MARK rule). It auto-expires so a crashed
        // process cannot wedge the note permanently.
        $lock = Cache::lock('delivery-submit:'.$note->getKey(), 120);
        if (! $lock->get()) {
            throw new RuntimeException(
                "Δελτίο {$note->invcode}: μια υποβολή στο myDATA είναι ήδη σε εξέλιξη — "
                .'περίμενε να ολοκληρωθεί πριν ξαναδοκιμάσεις.'
            );
        }

        try {
            // Re-read FRESH under the lock: a submit that just finished on another
            // worker may have flipped mydata_state to VALID, and the in-memory $note
            // (read before the lock) would be stale — the guards below must see the
            // committed state to refuse a second filing.
            $note->refresh();

            // blank(), not `=== null`: performSubmit explicitly treats '' as equally
            // never-filed, and an armed δελτίο carrying '' would otherwise skip
            // adopt-or-file entirely and blind-retry.
            if ($note->mydata_pending_since !== null && blank($note->mydata_state)) {
                $adopted = $this->adoptExistingMarkIfPresent($note);
                if ($adopted !== null) {
                    return $adopted;
                }

                // AADE showed nothing for this (series, ΑΑ), which is ambiguous: the
                // earlier POST may never have landed, OR it landed and the
                // RequestTransmittedDocs feed has not surfaced it yet (it lags a
                // fresh filing by a minute or two — sandbox-observed 2026-07-07 on
                // the invoice side, where an immediate re-check missed the MARK and
                // a naive resubmit produced a SECOND one). Refuse inside the grace
                // window; only once it has elapsed with AADE still empty do we
                // accept the POST was lost.
                $graceMinutes = (int) config('ekdosi.einvoice.in_doubt_grace_minutes', 10);
                if ($note->mydata_pending_since->gt(now()->subMinutes($graceMinutes))) {
                    throw new RuntimeException(
                        "Δελτίο {$note->invcode}: μια προηγούμενη υποβολή στο myDATA διακόπηκε "
                        ."και η ΑΑΔΕ δεν δείχνει ακόμη ΜΑΡΚ γι' αυτό το δελτίο. Επειδή το feed "
                        .'της ΑΑΔΕ καθυστερεί λίγα λεπτά, ΔΕΝ ξαναϋποβάλλουμε τυφλά (κίνδυνος '
                        ."διπλής έκδοσης). Περίμενε ~{$graceMinutes} λεπτά και ξαναδοκίμασε — "
                        .'αν εν τω μεταξύ βρεθεί ΜΑΡΚ, θα υιοθετηθεί αυτόματα.'
                    );
                }
            }

            return $this->performSubmit($note);
        } finally {
            $lock->release();
        }
    }

    private function performSubmit(DeliveryNote $note): DeliveryMark
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

        // MYD-021: never file a locally-voided δελτίο. The business cancelled this
        // movement; filing it would create a live AADE document the ledger does not
        // recognise. Guarded at SERVICE level so every caller is covered — the UI
        // hiding the button is not protection for a CLI, API or automation caller,
        // which is exactly where this would go unnoticed. (Twin of the invoice
        // guard in MyDataSubmitter::performSubmit.)
        if ($note->local_status === 'cancelled') {
            throw new RuntimeException(
                "Δελτίο {$note->invcode}: είναι ακυρωμένο τοπικά — δεν υποβάλλεται στο myDATA. "
                .'Επανάφερέ το πρώτα, αν η ακύρωση ήταν λάθος.'
            );
        }

        $payload = $this->buildAadeDeliveryNote($note);
        $xml = $this->payloadToXml($payload);

        if ($this->tenant->isLiveProviderTenant()) {
            return $this->submitViaProvider($note, $xml);
        }

        $this->initFirebed();

        $action = new SendInvoices;

        // MYD-021: ARM immediately before the outbound call — a catch only runs if
        // this process survives, so a hard kill between AADE accepting the request
        // and our catch would otherwise leave no trace and the next attempt would
        // POST blindly into a filing that already existed.
        //
        // AFTER the local pre-flight (initFirebed, and the provider branch's own
        // issue-date guard), not before: those throw without sending anything, and
        // arming there would lock the note out of the grace window over an error
        // the operator can fix in seconds. Arm as late as possible, but strictly
        // before the first byte leaves.
        $this->armInDoubt($note);

        try {
            $response = $action->handle($payload);
        } catch (MyDataAuthenticationException $e) {
            // 401 — AADE refused before processing, so no MARK exists.
            $this->disarmInDoubt($note);
            $this->logFailure($note, 'auth', $e);
            throw new RuntimeException('myDATA rejected credentials. Check Company → myDATA submission tab.', 0, $e);
        } catch (MyDataTimeoutException|MyDataConnectionException $e) {
            // AMBIGUOUS — the request may have reached AADE and produced a MARK we
            // never saw. STAYS ARMED so the next submit adopts instead of re-POSTing.
            $this->logFailure($note, 'transport', $e);
            throw new RuntimeException('myDATA endpoint unreachable. Try again later.', 0, $e);
        } catch (RateLimitExceededException $e) {
            // 429: AADE throttled BEFORE processing → no MARK. Caught before the
            // InvalidResponse/TransmissionFailed arm below, which it subclasses.
            $this->disarmInDoubt($note);
            $this->logFailure($note, 'rate-limit', $e);
            throw new RuntimeException('myDATA rate limit exceeded. Try again shortly.', 0, $e);
        } catch (InvalidResponseException|TransmissionFailedException $e) {
            // AMBIGUOUS: an empty/invalid HTTP-200 body, or a non-2xx transmission
            // failure (5xx). The POST may have reached AADE and created a MARK whose
            // response we never saw. These SUBCLASS MyDataException, so without this
            // dedicated arm they fell into the generic one below and DISARMED —
            // handing the next attempt a blind re-POST. The invoice path has had
            // this arm since MYD-2; the delivery twin was missing it. STAYS ARMED.
            $this->logFailure($note, 'ambiguous-response', $e);
            throw new RuntimeException('myDATA returned an unusable response. Try again later.', 0, $e);
        } catch (MyDataException $e) {
            // Any OTHER firebed protocol error is thrown before the POST → no MARK.
            $this->disarmInDoubt($note);
            $this->logFailure($note, 'protocol', $e);
            throw new RuntimeException('myDATA delivery-note submission failed: '.$e->getMessage(), 0, $e);
        } catch (Throwable $e) {
            // Any unexpected error during the POST is ambiguous too (e.g. a 2xx that
            // DID create a MARK but whose response failed to parse). STAYS ARMED.
            $this->logFailure($note, 'other', $e);
            throw new RuntimeException('myDATA delivery-note submission failed unexpectedly.', 0, $e);
        }

        $responseXml = $action->getResponseXML() ?? '';

        return $this->persistResponse($note, $xml, $response, $responseXml);
    }

    /** Submit the same canonical 9.x AADE XML through the tenant's ΥΠΑΗΕΣ provider. */
    private function submitViaProvider(DeliveryNote $note, string $xml): DeliveryMark
    {
        // Normal online provider issue requires IssueDate = today (InvoSign 238);
        // reject a backdated/future date locally before any outbound request (PROV-020).
        ProviderIssueDateGuard::assertIssuedToday($note->issued_at, (string) $note->invcode);

        $transport = app(ProviderTransportRegistry::class)->for((string) $this->tenant->einvoice_provider_key);
        $credentials = ProviderCredentials::fromCompany($this->tenant);

        // MYD-021: arm here, after the issue-date guard and transport resolution
        // above (both throw locally without sending anything) and immediately
        // before the outbound call.
        $this->armInDoubt($note);

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
            // The provider processed the document and refused it → no MARK. Disarm,
            // or an operator who fixes the data would sit out the grace window for
            // something already known not to have been filed. (A provider TRANSPORT
            // failure above stays armed — that one is genuinely ambiguous.)
            $this->disarmInDoubt($note);
            $this->recordProviderRejection($note, $transport->key(), $xml, $result);
            throw new DeliveryNoteRejected(
                'E-invoice provider rejected the delivery note: '.$result->errorMessage(),
                $xml,
                $result->raw ?? ''
            );
        }

        return $this->persistProviderSuccess($note, $transport->key(), $xml, $result);
    }

    /**
     * MYD-021: record the in-doubt marker BEFORE any outbound call.
     *
     * THROWS on failure, deliberately: a filing we could not record is exactly the
     * unrecoverable case this exists to eliminate. Failing costs one refused
     * attempt; proceeding risks a second legal document at AADE.
     */
    private function armInDoubt(DeliveryNote $note): void
    {
        try {
            $note->forceFill(['mydata_pending_since' => now()])->save();
        } catch (Throwable $e) {
            throw new RuntimeException(
                "Δελτίο {$note->invcode}: δεν μπόρεσε να καταγραφεί η σήμανση «σε εξέλιξη» "
                .'πριν την υποβολή, οπότε ΔΕΝ υποβάλλουμε — μια υποβολή χωρίς αυτήν δεν θα '
                .'μπορούσε να ανακτηθεί σε περίπτωση διακοπής. Δοκίμασε ξανά.',
                0,
                $e,
            );
        }
    }

    /**
     * Clear the marker after an outcome that PROVES no MARK was created (401, a
     * pre-send protocol error, an explicit provider rejection).
     *
     * Best-effort, unlike arming: the caller is about to re-throw the real error,
     * and a stuck marker only costs one grace window — it never risks a double
     * filing.
     */
    private function disarmInDoubt(DeliveryNote $note): void
    {
        try {
            $note->forceFill(['mydata_pending_since' => null])->save();
        } catch (Throwable $e) {
            Log::warning('Delivery: failed to clear the in-doubt flag after a no-MARK outcome', [
                'delivery_note_id' => $note->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Before resubmitting an in-doubt δελτίο, ask AADE whether the earlier
     * (ambiguous) POST actually landed, and adopt the MARK instead of filing a
     * second one.
     *
     * Reuses SalesReconciler: RequestTransmittedDocs does NOT filter by type, so a
     * 9.x δελτίο appears there exactly like an invoice, matched on the same frozen
     * (series, ΑΑ). Reusing the proven reader beats a second implementation of the
     * same lookup.
     *
     * A tenant that cannot READ myDATA (no read credentials at all) gets null —
     * the caller then refuses inside the grace window rather than retrying blindly.
     * That is the honest outcome: we cannot verify, so we do not gamble. The
     * provider-side durable-idempotency story is PROV-001.
     */
    private function adoptExistingMarkIfPresent(DeliveryNote $note): ?DeliveryMark
    {
        $series = $note->filedSeries();
        $aa = (string) $note->code;

        if (blank($series) || (int) $note->code < 1) {
            // No concrete (series, ΑΑ) to search by — the caller's own guards give a
            // clearer error than anything we could say here.
            return null;
        }

        if (! $this->tenant->canReadMyData()) {
            // NOT the same as "AADE has nothing": we never asked, so we must not let
            // the caller treat this as verified-empty INSIDE the dangerous window.
            //
            // But refusing forever is worse than the disease, and the first cut of
            // this fix did exactly that: a provider tenant with no myDATA read
            // credentials could never submit the note again, because nothing in the
            // app clears mydata_pending_since. A permanently unsubmittable legal
            // document is a bigger operational failure than the risk being avoided.
            //
            // So: refuse while the window is hot — that is when a MARK created by the
            // earlier attempt is most likely to exist and least likely to be visible
            // anywhere — and past it, allow the filing with a loud warning. The
            // operator has had the grace window to check the provider's portal.
            // Provider-side verification (InvoSign exposes an invoice_status
            // endpoint) is PROV-001; once that lands this branch becomes a real
            // check instead of a time-based one.
            $graceMinutes = (int) config('ekdosi.einvoice.in_doubt_grace_minutes', 10);

            if ($note->mydata_pending_since?->gt(now()->subMinutes($graceMinutes))) {
                throw new RuntimeException(
                    "Δελτίο {$note->invcode}: μια προηγούμενη υποβολή διακόπηκε και η εταιρεία δεν "
                    .'έχει διαπιστευτήρια ανάγνωσης myDATA για να επιβεβαιωθεί αν καταχωρήθηκε. '
                    ."Περίμενε ~{$graceMinutes} λεπτά και έλεγξε στο μεταξύ την πύλη του παρόχου· "
                    .'ΔΕΝ ξαναϋποβάλλουμε τυφλά μέσα στο κρίσιμο παράθυρο.'
                );
            }

            Log::warning('Delivery in-doubt: filing again WITHOUT verification (tenant cannot read myDATA)', [
                'company_id' => $this->tenant->getKey(),
                'delivery_note_id' => $note->id,
                'invcode' => $note->invcode,
                'pending_since' => $note->mydata_pending_since?->toIso8601String(),
            ]);

            return null;
        }

        $issued = Carbon::parse($note->issued_at);
        $from = $issued->copy()->min(now())->subDay();
        $to = $issued->copy()->max(now())->addDay();

        try {
            // FirebedCredentials, NOT initFirebed(): this is a READ, and the two
            // resolve different environments. initFirebed() primes the SUBMISSION
            // mode (`mydata_mode_enum`), which for a provider tenant is 'off' with
            // no credentials — so the lookup would either throw forever (stranding
            // the note, the very bug round 2 fixed, reached by another door) or, if
            // sandbox creds happen to exist, verify against the AADE DEV endpoint,
            // see nothing, and file a second δελτίο past the grace window.
            // mydataReadMode() — which canReadMyData() above already gated on — is
            // what FirebedCredentials resolves.
            FirebedCredentials::init($this->tenant, $this->mockHandler);
            $docs = (new SalesReconciler($this->tenant, $this->mockHandler))
                ->fetchAadeDocs($from->format('d/m/Y'), $to->format('d/m/Y'));
        } catch (Throwable $e) {
            // Cannot reach AADE to verify → must NOT resubmit blindly (that is the
            // whole risk). Re-throw; the note stays in-doubt for the next attempt.
            Log::warning('Delivery in-doubt: reconcile lookup failed — NOT resubmitting blindly', [
                'company_id' => $this->tenant->getKey(),
                'delivery_note_id' => $note->id,
                'invcode' => $note->invcode,
                'error' => $e->getMessage(),
            ]);

            throw new RuntimeException(
                "Δελτίο {$note->invcode}: μια προηγούμενη υποβολή διακόπηκε και το myDATA δεν "
                .'είναι προσβάσιμο για να επιβεβαιωθεί αν είχε καταχωρηθεί. Δεν ξαναϋποβάλλουμε '
                .'τυφλά — ξαναδοκίμασε όταν η ΑΑΔΕ είναι διαθέσιμη.',
                0,
                $e,
            );
        }

        $matches = array_values(array_filter(
            $docs,
            fn ($d): bool => (string) $d->series === (string) $series
                && (string) $d->aa === $aa
                && ! $d->cancelled
        ));

        if ($matches === []) {
            return null;
        }

        if (count($matches) > 1) {
            Log::warning('Delivery in-doubt: MULTIPLE live MARKs at AADE for one (series, ΑΑ) — adopting the first', [
                'company_id' => $this->tenant->getKey(),
                'delivery_note_id' => $note->id,
                'marks' => array_map(fn ($d) => $d->mark, $matches),
            ]);
        }

        $found = $matches[0];
        $mark = (string) $found->mark;

        $adopted = DB::transaction(function () use ($note, $found, $mark) {
            $row = DeliveryMark::query()
                ->where('delivery_note_id', $note->id)
                ->where('mark', $mark)
                ->whereIn('mydata_action', ['INSERT', 'PROVIDER_INSERT'])
                ->first()
                ?? DeliveryMark::create($this->deliveryMarkPayload([
                    'company_id' => $note->company_id,
                    'delivery_note_id' => $note->id,
                    'mark' => $mark,
                    'mydata_action' => 'INSERT',
                    // Both normal success paths store this; without it the adopted
                    // δελτίο's «Ιστορικό myDATA» row shows no QR link.
                    'invoice_url' => $found->qrCodeUrl,
                    'request' => null,
                    'response' => 'Adopted via RequestTransmittedDocs (MYD-021 in-doubt self-heal).',
                    'mark_date' => now()->toDateString(),
                    'mark_time' => now()->toTimeString(),
                ]));

            $note->forceFill([
                'mydata_sent' => true,
                'mydata_state' => 'VALID',
                'mydata_mark' => $mark,
                // AADE's own QR url, carried through from RequestTransmittedDocs.
                // Without it the lifecycle («Έναρξη διακίνησης») refuses the note for
                // having no qrUrl and tells the operator to RE-ISSUE — the exact
                // double-filing this adoption exists to prevent.
                'mydata_url' => $found->qrCodeUrl ?: $note->mydata_url,
                'recipient_country' => $this->filedCountry($note),
                'delivery_state' => 'registered',
                'local_status' => $note->local_status === 'draft' ? 'active' : $note->local_status,
                'mydata_pending_since' => null,
            ])->save();

            return $row;
        });

        // Same post-commit side effect both success paths perform: an adopted
        // δελτίο πώλησης is as filed as a freshly-submitted one, so its stock must
        // move too. Best-effort exactly like the other two — a stock hiccup must not
        // undo an adoption that has already reconciled a real AADE document.
        try {
            app(StockService::class)->recordSaleForDeliveryNote($note);
        } catch (Throwable $e) {
            Log::warning('Delivery adoption: stock movement failed', [
                'delivery_note_id' => $note->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $adopted;
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
                // Freeze the country we actually filed (MYD-011) — see filedCountry().
                'recipient_country' => $this->filedCountry($note),
                'delivery_state' => 'registered',
                'local_status' => $note->local_status === 'draft' ? 'active' : $note->local_status,
                // MYD-021: the filing is no longer in doubt — it is recorded.
                'mydata_pending_since' => null,
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
        // ONE definition of "external recipient" / "internal movement", on the model,
        // shared with the PDF, the CMR and the provider document (MYD-011).
        $externalAfm = $note->externalRecipientAfm();
        $afm = $externalAfm ?: DeliveryNote::INTERNAL_MOVEMENT_AFM;

        $country = $this->recipientCountry($note);

        // NOT the same test as the ΑΦΜ above: that one asks «is there an external
        // ΑΦΜ», this one asks «is there a recipient at all». They deliberately differ
        // for a sentinel note with a linked customer — the payload then carries the
        // customer's NAME with the 000000000 placeholder, because that party is a
        // real recipient who simply has no ΑΦΜ. The PDF shares isInternalMovement()
        // and prints the same pairing, so the two documents agree.
        // The issuer's name is the recipient ONLY for an ενδοδιακίνηση. Letting it
        // fall through for an external party filed a foreign counterpart under our
        // own identity (e.g. an ΑΦΜ-only recipient → «Ekdosi AE / 111111111 / DE»).
        $name = $note->isInternalMovement()
            ? ($this->tenant->name ?: throw new RuntimeException(
                "Delivery note {$note->invcode} is an internal movement but the issuer company has no name."
            ))
            : (($note->recipient_name ?: $note->customer?->name) ?: throw new RuntimeException(
                "Delivery note {$note->invcode} has an external recipient with no name. "
                .'AADE requires the counterpart name on a 9.x δελτίο — set «Επωνυμία παραλήπτη».'
            ));

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

    /**
     * The country this note was FILED with, for the snapshot column — never throws.
     *
     * `recipient_country` is documented (and relied on) as "what was submitted", but
     * a note can be issued with the country resolved from its linked customer, and
     * nothing wrote that back. The same transaction then sets `mydata_sent`, which
     * makes DeliveryNote::hasBeenFiled() true and cuts off the customer fallback —
     * so the country AADE holds became invisible to the PDF, the infolist and the
     * CMR the moment it was filed. Freezing it here is what makes the column true
     * to its name.
     *
     * It asks recipientCountry() FIRST rather than trusting a non-empty column: the
     * column is not authoritative on its own, because an ενδοδιακίνηση files GR
     * whatever it holds. Short-circuiting on `filled()` froze «DE» onto a note AADE
     * holds as GR — permanently, since a filed note is no longer editable. Asking
     * the resolver also stores the NORMALISED code («EL» → «GR»), which is what the
     * column claims to be.
     *
     * Never throws: we are past a successful AADE round-trip, so a resolution
     * failure here must not undo the persist. The catch is currently UNREACHABLE —
     * the payload is built (and the same resolver run) before either persist path,
     * and nothing mutates the note in between — so it is pure belt-and-braces. It
     * still normalises what it stores, because the column's whole contract is that
     * it holds a filed ISO-2 code, not the operator's alias.
     */
    private function filedCountry(DeliveryNote $note): ?string
    {
        try {
            return $this->recipientCountry($note);
        } catch (Throwable) {
            return IsoCountry::tryNormalise($note->recipient_country);
        }
    }

    /**
     * The recipient's ISO-3166-1 alpha-2 country (MYD-011).
     *
     * The recipient can be a customer, a SUPPLIER or a manual entry, and only a
     * customer carries a country through an FK — so the old
     * `customer?->country ?: 'GR'` silently filed every foreign supplier/manual
     * recipient as Greek. The note now freezes `recipient_country` at issue time
     * (populated in the form from whichever party was picked); the customer's own
     * country is only a fallback for notes written before that column existed.
     *
     * An unresolvable country is REFUSED, never defaulted: defaulting an external
     * party to GR is exactly the misreport this fixes. The single sanctioned GR
     * default is an ενδοδιακίνηση, where the recipient IS the (Greek) issuer.
     */
    private function recipientCountry(DeliveryNote $note): string
    {
        // Internal movement first: the recipient IS the (Greek) issuer, so NOTHING
        // else may override it — not a customer's country, and not a stale
        // recipient_country left behind by a party pick the operator then cleared.
        // (An earlier round put the explicit country first to rescue the "named
        // foreign party stored with the 000000000 placeholder" case; that case no
        // longer classifies as internal, since isInternalMovement() requires no name
        // and no customer either, so this order costs nothing.)
        if ($note->isInternalMovement()) {
            return 'GR';
        }

        if ($iso = $note->recipientCountryIso()) {
            return $iso;
        }

        // FOUR distinct failures reach here and the operator needs to be pointed at
        // the right field for each. Reporting them all as «not an ISO code» sent an
        // operator to inspect a customer country that was perfectly valid — the note
        // simply names somebody else.
        //
        // Ordered by WHAT THE OPERATOR MUST FIX, not by how the resolver failed: the
        // note's own «Χώρα παραλήπτη» comes first whenever it holds something,
        // because setting it resolves every one of the states below.
        $reason = match (true) {
            filled($note->recipient_country) => " («{$note->recipient_country}»: μη έγκυρος κωδικός ISO-3166-1 alpha-2)",

            // Already filed: recipientCountryIso() cuts the customer fallback off on
            // purpose, so the customer's country is irrelevant here however valid it
            // looks. Reachable through previewXml()/the sandbox commands, which is
            // exactly where an operator reads this text.
            $note->hasBeenFiled() => ' — το δελτίο έχει ήδη υποβληθεί χωρίς καταγεγραμμένη χώρα· '
                .'η τρέχουσα χώρα του πελάτη ΔΕΝ είναι αυτή που δηλώθηκε (βλ. MARK)',

            // A stale customer link: the note names a party other than the customer,
            // so that customer's country is irrelevant, valid or not.
            $note->customer_id !== null && ! $note->recipientIsTheLinkedCustomer() => ' — ο παραλήπτης '
                .'δεν είναι ο συνδεδεμένος πελάτης, οπότε η χώρα του πελάτη δεν ισχύει γι᾽ αυτόν',

            filled($note->customer?->country) => " («{$note->customer?->country}» στον πελάτη: μη έγκυρος κωδικός ISO-3166-1 alpha-2)",

            default => '',
        };

        // NO inference beyond this point — not from «this is one of our customers»,
        // not from a Greek-looking ΑΦΜ. Both were tried and both are guesses at the
        // FILING boundary: a foreign private individual, or a foreign customer with
        // thin legacy data, has a blank country and often a 9-digit number that
        // passes mod-11 about 1 time in 10 — and would be filed to AADE as Greek.
        // That is the whole of MYD-011. The single sanctioned GR default is a real
        // ενδοδιακίνηση (above); legacy domestic notes get a country by backfill or
        // by an operator edit, which is a data fix, not a misreport.
        throw new RuntimeException(
            "Delivery note {$note->invcode} has an external recipient but no usable country"
            .$reason
            .'. Set «Χώρα παραλήπτη» on the note — a foreign recipient must not be filed as GR.'
        );
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
            //
            // MYD-021: disarm ONLY on a real rejection — AADE answered, processed the
            // δελτίο and refused it, so no MARK exists and an operator who fixes the
            // data must be able to retry at once. A NULL $first is a different animal:
            // an empty or unparseable ResponseDoc tells us nothing about whether a
            // MARK was created, so it stays ARMED and the next attempt reconciles.
            if ($first !== null) {
                $this->disarmInDoubt($note);
            }
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
                // Freeze the country we actually filed (MYD-011) — see filedCountry().
                'recipient_country' => $this->filedCountry($note),
                'delivery_state' => 'registered',
                'local_status' => $note->local_status === 'draft' ? 'active' : $note->local_status,
                // MYD-021: the filing is no longer in doubt — it is recorded.
                'mydata_pending_since' => null,
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
