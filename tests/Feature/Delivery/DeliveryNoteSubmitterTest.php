<?php

namespace Tests\Feature\Delivery;

use App\Contracts\EInvoiceProviderTransport;
use App\Models\Company;
use App\Models\Customer;
use App\Models\DeliveryMark;
use App\Models\DeliveryNote;
use App\Models\DeliveryNoteLine;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Services\Delivery\DeliveryNoteRejected;
use App\Services\Delivery\DeliveryNoteSubmitter;
use App\Support\EInvoice\ProviderCredentials;
use App\Support\EInvoice\ProviderIssueDateGuard;
use App\Support\EInvoice\ProviderResult;
use App\Support\ProvisionalCode;
use Firebed\AadeMyData\Models\Invoice as AadeInvoice;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * No-network coverage for DeliveryNoteSubmitter. Build + previewXml run with no
 * AADE call; the live submit() happy-path uses a Guzzle MockHandler (same seam
 * as MyDataSubmitter's $mockHandler / firebed's MyDataRequest::setHandler).
 *
 * The asserted 9.3 value-less shape is grounded in firebed's reference payload
 * vendor/firebed/aade-mydata/stubs/request-doc-with-delivery-lifecycle.xml.
 */
class DeliveryNoteSubmitterTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private InvoiceType $deliveryType;

    private Customer $recipient;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Company::create([
            'name' => 'Delivery test',
            'slug' => 'deliv-'.uniqid(),
            'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata',
            'mydata_mode' => 'sandbox',
            'afm' => '800561849',
            'mydata_aade_id_sandbox' => 'TESTUSER',
            'mydata_subscription_key_sandbox' => 'TESTKEY',
        ]);

        $this->deliveryType = InvoiceType::create([
            'company_id' => $this->tenant->id,
            'code' => 'DA',
            'name' => 'Δελτίο Αποστολής',
            'invcount' => 1,
            'mydata_type' => '9.3',
        ]);

        $this->recipient = Customer::create([
            'company_id' => $this->tenant->id,
            'name' => 'Παραλήπτης ΑΕ',
            'afm' => '123456789',
        ]);
    }

    public function test_junk_in_the_recipient_afm_is_not_a_declaration_of_internal_movement(): void
    {
        // ROUND-8 P2-B (MYD-009's round-7 fix reached into MYD-011). Widening "all
        // zeros = no ΑΦΜ" to "anything unusable" swept «-» in with it — turning a
        // hard refusal into a silently-filed ενδοδιακίνηση, and replacing a linked
        // customer's KNOWN ΑΦΜ with the «no ΑΦΜ» placeholder. All-zeros is a
        // DECLARATION; junk is an accident, and an accident falls through.
        $note = $this->makeNote([
            'recipient_afm' => '-',
            'recipient_name' => null,
            'recipient_country' => null,
        ]);

        // customer_id is still set by makeNote, so this is NOT an internal movement.
        $this->assertFalse($note->isInternalMovement());
        $this->assertSame($this->recipient->afm, $note->externalRecipientAfm());
    }

    public function test_an_all_zeros_customer_afm_is_not_filed_as_an_identity(): void
    {
        // The customer-fallback branch was not canonicalised, so a customer row
        // holding «00000» was read verbatim as a real ΑΦΜ.
        $this->recipient->forceFill(['afm' => '00000'])->save();

        $note = $this->makeNote(['recipient_afm' => null]);

        $this->assertNull($note->fresh()->externalRecipientAfm());
    }

    public function test_junk_with_no_other_identity_is_refused_not_declared_internal(): void
    {
        // ROUND-9 P2. The round-8 fix only covered the case WITH a linked customer —
        // isInternalMovement() requires customer_id === null, so a note carrying junk
        // and NO other identity was still filed as "the issuer moved its own goods",
        // discarding the country the form made the operator pick. main refused it.
        $note = $this->makeNote([
            'customer_id' => null,
            'recipient_afm' => '-',
            'recipient_name' => null,
            'recipient_country' => 'DE',
        ]);

        $this->assertFalse($note->isInternalMovement(), 'junk is an identity attempt, not a declaration');

        $this->expectException(\RuntimeException::class);

        (new DeliveryNoteSubmitter($this->tenant))->buildAadeDeliveryNote($note);
    }

    public function test_a_prefixed_all_zeros_afm_is_the_same_declaration(): void
    {
        // ROUND-9 P2. isZeroPlaceholder() did not strip the Greek prefix while
        // canonicalVat() did, so «EL000000000» took the junk path and was replaced by
        // the linked customer's real ΑΦΜ — the placeholder filed as an identity.
        $note = $this->makeNote([
            'customer_id' => null,
            'recipient_afm' => 'EL000000000',
            'recipient_name' => null,
            'recipient_country' => null,
        ]);

        $this->assertTrue($note->isInternalMovement());
        $this->assertNull($note->externalRecipientAfm());
    }

    private function makeNote(array $overrides = []): DeliveryNote
    {
        $note = DeliveryNote::create(array_merge([
            'company_id' => $this->tenant->id,
            'invcode' => 'DA1',
            'code' => 1,
            'delivery_type_id' => $this->deliveryType->id,
            'customer_id' => $this->recipient->id,
            'issued_at' => now(),
            'mydata_type' => '9.3',
            'move_purpose' => 8,                 // Ενδοδιακίνηση
            'dispatch_at' => now()->addHour(),
            'vehicle_number' => 'ΙΑΒ1234',
            'third_party_collection' => false,
            'loading_street' => 'Φόρτωσης',
            'loading_number' => '10',
            'loading_postcode' => '11111',
            'loading_city' => 'Αθήνα',
            'start_shipping_branch' => 0,
            'delivery_street' => 'Παράδοσης',
            'delivery_number' => '20',
            'delivery_postcode' => '22222',
            'delivery_city' => 'Θεσσαλονίκη',
            'complete_shipping_branch' => 0,
            'recipient_name' => 'Παραλήπτης ΑΕ',
            'recipient_afm' => '123456789',
            // MYD-011: an external recipient MUST carry a country — the submitter
            // refuses rather than defaulting a foreign party to GR.
            'recipient_country' => 'GR',
            'local_status' => 'draft',
        ], $overrides));

        DeliveryNoteLine::create([
            'company_id' => $this->tenant->id,
            'delivery_note_id' => $note->id,
            'qty' => 3,
            'measurement_unit' => 1,
            'product_descr' => 'Κιβώτια',
        ]);

        return $note->fresh('lines');
    }

    public function test_the_9_3_line_carries_the_taric_and_item_code_snapshot(): void
    {
        $note = $this->makeNote(['invcode' => 'DAT1', 'code' => 501]);
        $note->lines()->first()->forceFill(['taric_code' => '8471300000', 'item_code' => 'SRV-R640'])->save();

        $detail = (new DeliveryNoteSubmitter($this->tenant))->buildAadeDeliveryNote($note->fresh('lines'))->getInvoiceDetails()[0];

        $this->assertSame('8471300000', $detail->getTaricNo());
        $this->assertSame('SRV-R640', $detail->getItemCode());
    }

    /* ============ MYD-011: recipient country is never guessed as GR ============ */

    private int $countryProbe = 0;

    private function counterpartCountryFor(array $overrides): string
    {
        // Distinct invcode/code per probe — (company_id, invcode) is unique, so a
        // single test can build several notes (e.g. EL and UK).
        $this->countryProbe++;
        $note = $this->makeNote(array_merge([
            'invcode' => 'DAC'.$this->countryProbe,
            'code' => 100 + $this->countryProbe,
        ], $overrides));
        $aade = (new DeliveryNoteSubmitter($this->tenant))->buildAadeDeliveryNote($note);

        // firebed's Party::getCountry(): ?string — setCountry() already unwraps a
        // CountryCode enum to its string value before storing.
        return (string) $aade->getCounterpart()->getCountry();
    }

    public function test_the_delivery_payload_uses_the_frozen_series_after_a_rename(): void
    {
        // MYD-018: the series was read live from the delivery type, so renaming it
        // rewrote what an already-numbered ΔΑ claimed to be — including notes AADE
        // already holds under the original series.
        $note = $this->makeNote();
        $this->assertSame('DA', $note->series, 'series frozen at create');

        $this->deliveryType->update(['code' => 'DANEW']);

        $header = (new DeliveryNoteSubmitter($this->tenant))
            ->buildAadeDeliveryNote($note->fresh())
            ->getInvoiceHeader();

        $this->assertSame('DA', $header->getSeries());
    }

    public function test_supplier_or_manual_foreign_recipient_keeps_its_country(): void
    {
        // THE BUG: a supplier/manual recipient leaves customer_id null, and the old
        // code read the country ONLY from note.customer — so every foreign
        // supplier/manual party was serialized as GR. The frozen recipient_country
        // is now the authoritative source.
        $this->assertSame('DE', $this->counterpartCountryFor([
            'customer_id' => null,                 // supplier / manual recipient
            'recipient_name' => 'Lieferant GmbH',
            'recipient_afm' => 'DE811234567',
            'recipient_country' => 'DE',
        ]));
    }

    public function test_customer_recipient_uses_the_frozen_country(): void
    {
        $this->assertSame('CY', $this->counterpartCountryFor(['recipient_country' => 'CY']));
    }

    public function test_el_and_uk_aliases_normalise_to_gr_and_gb(): void
    {
        // The delivery normalizer used to lack these aliases (the invoice one had
        // them), so 'EL'/'UK' passed straight through as non-ISO codes.
        $this->assertSame('GR', $this->counterpartCountryFor(['recipient_country' => 'EL']));
        $this->assertSame('GB', $this->counterpartCountryFor(['recipient_country' => 'UK']));
    }

    public function test_non_eu_recipient_country_is_preserved(): void
    {
        $this->assertSame('US', $this->counterpartCountryFor([
            'customer_id' => null,
            'recipient_name' => 'Acme Inc',
            'recipient_afm' => '987654321',
            'recipient_country' => 'US',
        ]));
    }

    public function test_falls_back_to_the_customer_country_for_notes_predating_the_column(): void
    {
        // Back-compat: notes issued before recipient_country existed still resolve
        // through the linked customer (whose country is free text).
        $this->recipient->forceFill(['country' => 'Germany'])->save();

        $this->assertSame('DE', $this->counterpartCountryFor(['recipient_country' => null]));
    }

    public function test_internal_movement_without_a_recipient_is_gr(): void
    {
        // Ενδοδιακίνηση: no external ΑΦΜ at all → AADE's 000000000 sentinel and the
        // recipient IS the (Greek) issuer. This is the ONE sanctioned GR default.
        $note = $this->makeNote([
            'customer_id' => null,
            'recipient_afm' => null,
            'recipient_name' => null,
            'recipient_country' => null,
        ]);

        $counterpart = (new DeliveryNoteSubmitter($this->tenant))
            ->buildAadeDeliveryNote($note)
            ->getCounterpart();

        $this->assertSame('000000000', $counterpart->getVatNumber());
        $this->assertSame('GR', (string) $counterpart->getCountry());
    }

    public function test_explicit_000000000_sentinel_is_still_an_internal_movement(): void
    {
        // The sentinel IS the marker for an ενδοδιακίνηση and IS stored that way
        // (the demo seeder writes it; the form/infolist tell operators to expect
        // it; the PDF treats it as internal). Reading it as an "external ΑΦΜ" would
        // hard-refuse a note that filed correctly before — so it must resolve to GR.
        $note = $this->makeNote([
            'customer_id' => null,
            'recipient_name' => null,
            'recipient_afm' => '000000000',
            'recipient_country' => null,
        ]);

        $counterpart = (new DeliveryNoteSubmitter($this->tenant))
            ->buildAadeDeliveryNote($note)
            ->getCounterpart();

        $this->assertSame('000000000', $counterpart->getVatNumber());
        $this->assertSame('GR', (string) $counterpart->getCountry());
    }

    public function test_named_foreign_recipient_without_an_afm_is_not_treated_as_internal(): void
    {
        // A manual foreign party with no ΑΦΜ (non-VAT / private recipient). Keying
        // "internal" on the ΑΦΜ alone would file "Müller GmbH" as GR with the
        // 000000000 sentinel — the same misreport MYD-011 exists to stop. A typed
        // name means there IS an external recipient, so a country is required.
        $note = $this->makeNote([
            'customer_id' => null,
            'recipient_name' => 'Müller GmbH',
            'recipient_afm' => null,
            'recipient_country' => null,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/country/i');

        (new DeliveryNoteSubmitter($this->tenant))->buildAadeDeliveryNote($note);
    }

    public function test_named_foreign_recipient_without_an_afm_keeps_its_country(): void
    {
        // …and with the country supplied it files as that country, still with the
        // 000000000 ΑΦΜ (AADE's placeholder for a recipient without one).
        $note = $this->makeNote([
            'customer_id' => null,
            'recipient_name' => 'Müller GmbH',
            'recipient_afm' => null,
            'recipient_country' => 'DE',
        ]);

        $counterpart = (new DeliveryNoteSubmitter($this->tenant))
            ->buildAadeDeliveryNote($note)
            ->getCounterpart();

        $this->assertSame('DE', (string) $counterpart->getCountry());
        $this->assertSame('Müller GmbH', $counterpart->getName());
    }

    public function test_a_greek_looking_afm_is_not_evidence_of_a_greek_country(): void
    {
        // A structurally valid Greek ΑΦΜ was briefly treated as positive evidence
        // that a CUSTOMER-linked party is Greek, to spare legacy domestic notes
        // whose customers.country is blank. It is not evidence: a bare 9-digit
        // foreign VAT id satisfies mod-11 about 1 time in 10, and a foreign private
        // individual or a thin legacy customer record looks exactly like this. The
        // filing boundary does not guess — legacy rows get a country by backfill or
        // by an operator edit.
        $this->recipient->forceFill(['afm' => '800561849', 'country' => null])->save();

        $note = $this->makeNote([
            'recipient_afm' => '800561849',
            'recipient_country' => null,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/country/i');

        (new DeliveryNoteSubmitter($this->tenant))->buildAadeDeliveryNote($note);
    }

    public function test_a_foreign_vat_id_does_not_infer_gr(): void
    {
        // 'DE811234567' must NOT be digit-stripped to '811234567' (which happens to
        // satisfy the Greek checksum) — that would re-introduce the exact misreport.
        $note = $this->makeNote([
            'customer_id' => null,
            'recipient_name' => 'Lieferant GmbH',
            'recipient_afm' => 'DE811234567',
            'recipient_country' => null,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/country/i');

        (new DeliveryNoteSubmitter($this->tenant))->buildAadeDeliveryNote($note);
    }

    public function test_explicit_sentinel_wins_over_a_linked_customer_afm(): void
    {
        // The operator declared an ενδοδιακίνηση by storing 000000000. Falling back
        // to the linked customer's ΑΦΜ would file a DIFFERENT legal counterpart
        // than the one declared, and demand a country for it.
        $this->recipient->forceFill(['afm' => '800561849', 'country' => 'GR'])->save();

        $note = $this->makeNote([
            'recipient_afm' => '000000000',      // customer_id still set
            'recipient_country' => null,
        ]);

        $counterpart = (new DeliveryNoteSubmitter($this->tenant))
            ->buildAadeDeliveryNote($note)
            ->getCounterpart();

        $this->assertSame('000000000', $counterpart->getVatNumber());
        $this->assertSame('GR', (string) $counterpart->getCountry());
    }

    public function test_explicit_country_wins_even_when_the_sentinel_afm_is_stored(): void
    {
        // THE original MYD-011 misreport, reachable until round 5: a named foreign
        // recipient with no ΑΦΜ is stored with the 000000000 placeholder (the only
        // one the UI offers), and classifying that as an ενδοδιακίνηση BEFORE
        // reading the operator's own country filed «Müller GmbH / 000000000 / GR».
        // An explicitly picked country must beat the sentinel classification.
        $note = $this->makeNote([
            'customer_id' => null,
            'recipient_name' => 'Müller GmbH',
            'recipient_afm' => '000000000',
            'recipient_country' => 'DE',
        ]);

        $counterpart = (new DeliveryNoteSubmitter($this->tenant))
            ->buildAadeDeliveryNote($note)
            ->getCounterpart();

        $this->assertSame('DE', (string) $counterpart->getCountry());
        $this->assertSame('Müller GmbH', $counterpart->getName());
    }

    public function test_sentinel_with_a_linked_customer_is_external_and_agrees_with_the_pdf(): void
    {
        // A stored sentinel plus a linked customer is NOT an ενδοδιακίνηση — there
        // IS a recipient, they just have no ΑΦΜ. The payload files the customer's
        // name with the 000000000 placeholder, and because the PDF now shares
        // isInternalMovement() it prints the same thing instead of «Ενδοδιακίνηση».
        // The customer carries a country: filing an UNIDENTIFIED counterpart no
        // longer earns a GR default either (see the refusal test below).
        $this->recipient->forceFill(['country' => 'GR'])->save();

        $note = $this->makeNote([
            'recipient_afm' => '000000000',   // customer_id still set
            'recipient_name' => null,
            'recipient_country' => null,
        ]);

        $this->assertFalse($note->isInternalMovement());

        $counterpart = (new DeliveryNoteSubmitter($this->tenant))
            ->buildAadeDeliveryNote($note)
            ->getCounterpart();

        $this->assertSame('000000000', $counterpart->getVatNumber());
        $this->assertSame($this->recipient->name, $counterpart->getName());
        $this->assertSame('GR', (string) $counterpart->getCountry());
    }

    public function test_sentinel_with_a_foreign_customer_files_that_customer_country(): void
    {
        // Round 3 guarded this as GR because the payload and the PDF disagreed about
        // whether it was an ενδοδιακίνηση. They now share isInternalMovement(), and
        // with the disagreement gone the honest answer is the customer's real
        // country: there IS a recipient (a German customer), they simply have no
        // ΑΦΜ, so we file the 000000000 placeholder with country DE — not a
        // fabricated GR.
        $this->recipient->forceFill(['country' => 'DE'])->save();

        $note = $this->makeNote([
            'recipient_afm' => '000000000',
            'recipient_country' => null,
        ]);

        $this->assertFalse($note->isInternalMovement(), 'a linked customer is a recipient');

        $counterpart = (new DeliveryNoteSubmitter($this->tenant))
            ->buildAadeDeliveryNote($note)
            ->getCounterpart();

        $this->assertSame('000000000', $counterpart->getVatNumber());
        $this->assertSame('DE', (string) $counterpart->getCountry());
    }

    public function test_a_customer_link_alone_does_not_earn_a_gr_default(): void
    {
        // The other half of the removed inference: filing AADE's «000000000»
        // placeholder for one of OUR customers was briefly treated as grounds for
        // GR ("we file this party as unidentified anyway"). But a foreign customer
        // with thin legacy data has exactly this shape, and the note is being FILED
        // — so it is refused instead, naming the field to fix.
        $this->recipient->forceFill(['country' => null])->save();

        $note = $this->makeNote([
            'recipient_afm' => null,          // customer_id still set → not internal
            'recipient_country' => null,
        ]);

        $this->assertFalse($note->isInternalMovement());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/country|χώρα/iu');

        (new DeliveryNoteSubmitter($this->tenant))->buildAadeDeliveryNote($note);
    }

    public function test_a_stale_customer_link_does_not_lend_its_country_to_another_party(): void
    {
        // THE ROUND-9 P1. `customer_id` is a Hidden the form never clears, so an
        // operator can pick a Greek customer and then overtype the recipient with a
        // German one. The customer fallback fired unconditionally, so the note was
        // filed as «Müller GmbH / DE811234567 / GR» — a foreign party reported as
        // Greek, with its own DE VAT id in the same counterpart as contrary
        // evidence. Round 8 removed the inferences; this was the same guess by
        // another route.
        $this->recipient->forceFill(['country' => 'ΕΛΛΑΔΑ', 'afm' => '800561849'])->save();

        $note = $this->makeNote([
            'recipient_name' => 'Müller GmbH',      // customer_id still set
            'recipient_afm' => 'DE811234567',
            'recipient_country' => null,
        ]);

        $this->assertFalse($note->recipientIsTheLinkedCustomer());

        try {
            $country = (string) (new DeliveryNoteSubmitter($this->tenant))
                ->buildAadeDeliveryNote($note)->getCounterpart()->getCountry();
            $this->fail("Expected a refusal, filed country={$country} for a German party");
        } catch (\RuntimeException $e) {
            // …and the message must name the RIGHT field. «ΕΛΛΑΔΑ» normalises fine,
            // so reporting it as an invalid ISO code sends the operator to inspect a
            // customer country that was never the problem.
            $this->assertStringContainsString('δεν είναι ο συνδεδεμένος πελάτης', $e->getMessage());
            $this->assertStringNotContainsString('ΕΛΛΑΔΑ', $e->getMessage());
        }
    }

    public function test_the_customer_fallback_still_works_when_the_recipient_is_that_customer(): void
    {
        // The narrowing must not strand the normal case — the picker copies the
        // party's own name/ΑΦΜ into the recipient fields, so they routinely match.
        $this->recipient->forceFill(['country' => 'Germany', 'afm' => '800561849'])->save();

        $this->assertSame('DE', $this->counterpartCountryFor([
            'recipient_name' => $this->recipient->name,
            'recipient_afm' => '800561849',
            'recipient_country' => null,
        ]));
    }

    public function test_a_greek_checksum_on_a_supplier_or_manual_recipient_is_refused(): void
    {
        // A bare 9-digit foreign VAT passes the Greek mod-11 check ~1 time in 10.
        $note = $this->makeNote([
            'customer_id' => null,
            'recipient_name' => 'Lieferant GmbH',
            'recipient_afm' => '811234567',   // valid Greek checksum, foreign party
            'recipient_country' => null,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/country/i');

        (new DeliveryNoteSubmitter($this->tenant))->buildAadeDeliveryNote($note);
    }

    public function test_external_recipient_without_a_country_is_refused_not_defaulted_to_gr(): void
    {
        // The heart of MYD-011: an identifiable recipient with no resolvable country
        // must FAIL LOUDLY. Silently filing it as GR is the misreport being fixed.
        $note = $this->makeNote([
            'customer_id' => null,
            'recipient_name' => 'Unknown Ltd',
            'recipient_afm' => '999888777',
            'recipient_country' => null,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/country/i');

        (new DeliveryNoteSubmitter($this->tenant))->buildAadeDeliveryNote($note);
    }

    public function test_external_recipient_with_an_unrecognised_country_is_refused(): void
    {
        // 'ZZ' is two letters but NOT a real ISO code — it fits the varchar(2)
        // column, so this is the shape a typo actually takes in production.
        $note = $this->makeNote([
            'customer_id' => null,
            'recipient_name' => 'Nowhere Ltd',
            'recipient_afm' => '999888777',
            'recipient_country' => 'ZZ',
        ]);

        $this->expectException(\RuntimeException::class);

        (new DeliveryNoteSubmitter($this->tenant))->buildAadeDeliveryNote($note);
    }

    public function test_an_unresolvable_country_is_never_overridden_by_a_gr_inference(): void
    {
        // THE ROUND-7 P0. Both GR inferences were gated on customer_id alone, so
        // they fired whenever the country merely failed to NORMALISE — not only
        // when it was absent. A customer recorded as 'Neverland' with no ΑΦΜ was
        // therefore filed as GR: the MYD-011 misreport surviving on the customer
        // path, and reachable from «Έκδοση» on an existing draft (which never
        // re-runs the form's required()).
        // The recipient IS this customer (same name and ΑΦΜ) — otherwise the note
        // would be refused for the stale-link reason instead, and this test would
        // stop covering the inference hole it exists for.
        $this->recipient->forceFill(['country' => 'Neverland', 'afm' => '800561849'])->save();

        foreach ([null, '800561849'] as $afm) {   // no-ΑΦΜ branch, then Greek-ΑΦΜ branch
            $note = $this->makeNote([
                'invcode' => 'DAU'.($afm ?? 'null'),
                'code' => 700 + strlen((string) $afm),
                'recipient_afm' => $afm,
                'recipient_country' => null,      // only the customer's free text remains
            ]);

            try {
                $country = (string) (new DeliveryNoteSubmitter($this->tenant))
                    ->buildAadeDeliveryNote($note)->getCounterpart()->getCountry();
                $this->fail("Expected a refusal, filed country={$country} for afm=".var_export($afm, true));
            } catch (\RuntimeException $e) {
                // The message must quote the offending value — the operator has to
                // know WHICH field to fix.
                $this->assertStringContainsString('Neverland', $e->getMessage());
            }
        }
    }

    public function test_a_recorded_country_that_is_not_iso_is_refused_even_with_a_greek_afm(): void
    {
        // Same hole via the note's own column rather than the customer's: 'ZZ' is
        // storable, unresolvable, and sits next to a mod-11-valid Greek ΑΦΜ, which
        // used to be enough to infer GR.
        $note = $this->makeNote([
            'recipient_afm' => '800561849',
            'recipient_country' => 'ZZ',
        ]);

        $this->expectException(\RuntimeException::class);

        (new DeliveryNoteSubmitter($this->tenant))->buildAadeDeliveryNote($note);
    }

    public function test_legacy_free_text_country_names_still_resolve(): void
    {
        // The refusal above is only safe because the normaliser resolves the
        // free-text spellings the legacy Firebird import actually left behind —
        // otherwise fixing the P0 would have made those notes unissuable.
        $this->recipient->forceFill(['country' => 'ΙΤΑΛΙΑ'])->save();
        $this->assertSame('IT', $this->counterpartCountryFor(['recipient_country' => null]));

        $this->recipient->forceFill(['country' => 'Ελλάδα'])->save();
        $this->assertSame('GR', $this->counterpartCountryFor(['recipient_country' => null]));
    }

    public function test_build_assembles_delivery_header_and_value_less_line(): void
    {
        $note = $this->makeNote();

        $aade = (new DeliveryNoteSubmitter($this->tenant))->buildAadeDeliveryNote($note);

        $this->assertInstanceOf(AadeInvoice::class, $aade);

        $header = $aade->getInvoiceHeader();
        // AADE FORBIDS <isDeliveryNote> and <currency> for a 9.x type ([205],
        // sandbox-proven 2026-06-03) — the 9.x type already marks it a δελτίο.
        $this->assertNotTrue($header->getIsDeliveryNote());
        $this->assertNull($header->getCurrency());
        $this->assertSame(8, $header->getMovePurpose()->value);
        $this->assertSame('9.3', $header->getInvoiceType()->value);

        // Issuer + counterpart carry full identification (name + address) —
        // mandatory for 9.x ([204]), unlike the monetary-invoice GR rule.
        $this->assertSame('Delivery test', $aade->getIssuer()->getName());
        $this->assertNotNull($aade->getIssuer()->getAddress());
        $this->assertSame('Παραλήπτης ΑΕ', $aade->getCounterpart()->getName());
        $this->assertSame('Παράδοσης', $aade->getCounterpart()->getAddress()->getStreet());

        // OtherDeliveryNoteHeader carries both addresses.
        $odh = $header->getOtherDeliveryNoteHeader();
        $this->assertNotNull($odh);
        $this->assertSame('Φόρτωσης', $odh->getLoadingAddress()->getStreet());
        $this->assertSame('Παράδοσης', $odh->getDeliveryAddress()->getStreet());

        // Exactly one value-less line: quantity present, zero net/vat, category 8.
        $details = $aade->getInvoiceDetails();
        $this->assertCount(1, $details);
        $line = $details[0];
        $this->assertSame(3.0, (float) $line->getQuantity());
        $this->assertSame(0.0, (float) $line->getNetValue());
        $this->assertSame(0.0, (float) $line->getVatAmount());
        // vatCategory 8 = Εγγραφές χωρίς ΦΠΑ (no VAT).
        $this->assertSame('8', (string) ($line->getVatCategory()->value ?? $line->getVatCategory()));
    }

    public function test_correlated_9_1_delivery_type_is_blocked(): void
    {
        // 9.1 (συσχετιζόμενο) needs a correlated-MARK payload we don't build →
        // block at the service, not just the picker (MYD-012).
        $note = $this->makeNote(['mydata_type' => '9.1']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/δεν υποστηρίζεται/u');

        (new DeliveryNoteSubmitter($this->tenant))->previewXml($note);
    }

    public function test_aggregate_9_2_delivery_type_is_blocked(): void
    {
        $note = $this->makeNote(['mydata_type' => '9.2']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/δεν υποστηρίζεται/u');

        (new DeliveryNoteSubmitter($this->tenant))->previewXml($note);
    }

    public function test_future_9_4_delivery_type_is_blocked_by_allowlist(): void
    {
        // A hypothetical future 9.x we haven't built must be blocked too — the
        // allowlist (only 9.3 today) is the point: a denylist of 9.1/9.2 would
        // let 9.4 slip through and file an unbuilt payload (MYD-012).
        $note = $this->makeNote(['mydata_type' => '9.4']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/δεν υποστηρίζεται/u');

        (new DeliveryNoteSubmitter($this->tenant))->previewXml($note);
    }

    public function test_missing_measurement_unit_throws(): void
    {
        // A persisted line with no unit is a data error — surface it, never
        // silently file it as pieces (MYD-016).
        $note = $this->makeNote();
        $note->lines()->update(['measurement_unit' => null]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/μονάδα μέτρησης/u');

        (new DeliveryNoteSubmitter($this->tenant))->previewXml($note->fresh('lines'));
    }

    public function test_out_of_range_measurement_unit_throws(): void
    {
        $note = $this->makeNote();
        $note->lines()->update(['measurement_unit' => 99]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/μονάδα μέτρησης/u');

        (new DeliveryNoteSubmitter($this->tenant))->previewXml($note->fresh('lines'));
    }

    public function test_unit_7_other_is_blocked_until_other_unit_fields_exist(): void
    {
        // Unit 7 needs otherMeasurementUnitQuantity/Title (§8.13 note 9) — block
        // it locally instead of filing a payload AADE must reject.
        $note = $this->makeNote();
        $note->lines()->update(['measurement_unit' => 7]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/δεν υποστηρίζεται/u');

        (new DeliveryNoteSubmitter($this->tenant))->previewXml($note->fresh('lines'));
    }

    public function test_supported_unit_survives_unchanged(): void
    {
        // Kilos (2) must reach the payload as-is, not be rewritten to pieces.
        $note = $this->makeNote();
        $note->lines()->update(['measurement_unit' => 2]);

        $aade = (new DeliveryNoteSubmitter($this->tenant))->buildAadeDeliveryNote($note->fresh('lines'));
        $mu = $aade->getInvoiceDetails()[0]->getMeasurementUnit();
        $this->assertSame('2', (string) ($mu->value ?? $mu));
    }

    public function test_preview_xml_contains_delivery_markers(): void
    {
        $note = $this->makeNote();

        $xml = (new DeliveryNoteSubmitter($this->tenant))->previewXml($note);

        $this->assertNotEmpty($xml);
        $this->assertStringContainsString('<invoiceType>9.3</invoiceType>', $xml);
        // AADE FORBIDS these for a 9.x type ([205]/[214], sandbox-proven 2026-06-03).
        $this->assertStringNotContainsString('<isDeliveryNote>', $xml);
        $this->assertStringNotContainsString('<currency>', $xml);
        $this->assertStringNotContainsString('<thirdPartyCollection>', $xml); // only sent when true
        $this->assertStringContainsString('<movePurpose>8</movePurpose>', $xml);
        $this->assertStringContainsString('<otherDeliveryNoteHeader>', $xml);
        $this->assertStringContainsString('<loadingAddress>', $xml);
        $this->assertStringContainsString('<deliveryAddress>', $xml);
        $this->assertStringContainsString('<vatCategory>8</vatCategory>', $xml);
        // Full issuer + counterpart identification is mandatory for 9.x ([204]).
        $this->assertStringContainsString('<name>Delivery test</name>', $xml);   // issuer
        $this->assertStringContainsString('<name>Παραλήπτης ΑΕ</name>', $xml);    // counterpart
        // «Χαρακτηρισμός Συναλλαγών 3 = Διακίνηση» — mandatory per Α.1123/2024 §5.2.2.
        $this->assertStringContainsString('category3', $xml);
        // Value-less: no payment methods on a delivery note.
        $this->assertStringNotContainsString('<paymentMethods>', $xml);
        // Per-line <itemDescr> IS carried for a delivery note (AADE accepts it for
        // 9.x) — this is the live itemDescr path now that a 9.x can't be a monetary
        // invoice (MYD-003); the monetary knob branch is unreachable (ItemDescrKnobTest).
        $this->assertStringContainsString('<itemDescr>Κιβώτια</itemDescr>', $xml);
    }

    public function test_endodiakinisi_recipient_is_nine_zeros(): void
    {
        // No recipient (own-branch move) → recipient ΑΦΜ = 000000000 per the law,
        // never an omitted counterpart.
        $note = $this->makeNote(['customer_id' => null, 'recipient_afm' => null, 'recipient_name' => null]);

        $xml = (new DeliveryNoteSubmitter($this->tenant))->previewXml($note);

        $this->assertStringContainsString('<counterpart>', $xml);
        $this->assertStringContainsString('000000000', $xml);
    }

    public function test_other_move_purpose_title_required_for_purpose_19(): void
    {
        $note = $this->makeNote(['move_purpose' => 19, 'other_move_purpose_title' => null]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/other_move_purpose_title/');

        (new DeliveryNoteSubmitter($this->tenant))->previewXml($note);
    }

    public function test_blocked_move_purpose_is_rejected(): void
    {
        // §8.14: purpose 18 (Διακίνηση Παγίων) is no longer transmittable.
        $note = $this->makeNote(['move_purpose' => 18]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/no longer/');

        (new DeliveryNoteSubmitter($this->tenant))->previewXml($note);
    }

    public function test_draft_without_aa_number_throws(): void
    {
        $note = $this->makeNote(['code' => 0, 'invcode' => 'DA0']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/no ΑΑ number/');

        (new DeliveryNoteSubmitter($this->tenant))->previewXml($note);
    }

    public function test_blank_delivery_address_throws_instead_of_filing_placeholder(): void
    {
        // A blank mandatory address must hard-fail, not file '00000'/'Άγνωστη'
        // into a legal e-transport record.
        $note = $this->makeNote(['delivery_city' => '']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/διεύθυνση παράδοσης/');

        (new DeliveryNoteSubmitter($this->tenant))->previewXml($note);
    }

    public function test_submit_freezes_the_country_it_actually_filed(): void
    {
        // ROUND-10 P1. A note can be issued with the country resolved from its
        // linked customer, and nothing wrote that back — while the SAME forceFill
        // set mydata_sent, which makes hasBeenFiled() true and cuts off exactly that
        // fallback. So the country AADE holds became invisible the moment it was
        // filed: no «Χώρα» line on the PDF, «δεν καταγράφηκε» in the infolist, and
        // the CMR silently reading the LIVE customer country instead — the very leak
        // the hasBeenFiled() gate exists to close.
        $this->recipient->forceFill(['country' => 'Germany', 'afm' => '800561849'])->save();

        $note = $this->makeNote([
            'recipient_name' => $this->recipient->name,
            'recipient_afm' => '800561849',
            'recipient_country' => null,      // resolved from the customer at issue
        ]);

        $mock = new MockHandler([
            new GuzzleResponse(200, [], $this->successResponseXml()),
        ]);
        $mark = (new DeliveryNoteSubmitter($this->tenant, $mock))->submit($note);

        // What went to AADE…
        $this->assertStringContainsString('<country>DE</country>', (string) $mark->request);

        // …is what the column now holds, so every reader agrees with the filing
        // even though the customer fallback is closed from here on.
        $fresh = $note->fresh();
        $this->assertTrue($fresh->hasBeenFiled());
        $this->assertSame('DE', $fresh->recipient_country);
        $this->assertSame('DE', $fresh->recipientCountryIso());

        // And it survives the customer moving afterwards.
        $this->recipient->forceFill(['country' => 'FR'])->save();
        $this->assertSame('DE', $note->fresh()->recipientCountryIso());
    }

    public function test_an_internal_movement_freezes_gr_not_a_stale_column_value(): void
    {
        // The freeze must record what was FILED, not what the column happened to
        // hold. An ενδοδιακίνηση files GR whatever `recipient_country` says, so
        // trusting a non-empty column froze «DE» onto a note AADE holds as GR —
        // permanently, since a filed note is no longer editable.
        $note = $this->makeNote([
            'customer_id' => null,
            'recipient_name' => null,
            'recipient_afm' => null,
            'recipient_country' => 'DE',      // stale: there is no recipient at all
        ]);
        $this->assertTrue($note->isInternalMovement());

        $mock = new MockHandler([new GuzzleResponse(200, [], $this->successResponseXml())]);
        $mark = (new DeliveryNoteSubmitter($this->tenant, $mock))->submit($note);

        $this->assertStringContainsString('<country>GR</country>', (string) $mark->request);
        $this->assertSame('GR', $note->fresh()->recipient_country);
    }

    public function test_the_frozen_country_is_the_normalised_code(): void
    {
        // The column is documented as "what was submitted", so it must hold the code
        // that went out (GR), not the operator's alias (EL).
        $note = $this->makeNote(['recipient_country' => 'EL']);

        $mock = new MockHandler([new GuzzleResponse(200, [], $this->successResponseXml())]);
        $mark = (new DeliveryNoteSubmitter($this->tenant, $mock))->submit($note);

        $this->assertStringContainsString('<country>GR</country>', (string) $mark->request);
        $this->assertSame('GR', $note->fresh()->recipient_country);
    }

    public function test_a_foreign_vat_prefix_is_not_stripped_when_matching_the_linked_customer(): void
    {
        // Afm::digits('DE811234567') is '811234567', which matches a Greek customer's
        // ΑΦΜ — so the identity check said "this IS our customer", the note inherited
        // that customer's country, and a German party was filed as GR with its own DE
        // prefix in the same counterpart. A country prefix is evidence, not noise.
        $this->recipient->forceFill(['afm' => '811234567', 'country' => 'ΕΛΛΑΔΑ'])->save();

        $note = $this->makeNote([
            'recipient_name' => null,               // blank → would file the customer's name
            'recipient_afm' => 'DE811234567',
            'recipient_country' => null,
        ]);

        $this->assertFalse($note->recipientIsTheLinkedCustomer());

        $this->expectException(\RuntimeException::class);

        (new DeliveryNoteSubmitter($this->tenant))->buildAadeDeliveryNote($note);
    }

    public function test_submit_persists_mark_qr_and_delivery_mark_row(): void
    {
        $note = $this->makeNote();

        $mock = new MockHandler([
            new GuzzleResponse(200, [], $this->successResponseXml()),
        ]);

        $mark = (new DeliveryNoteSubmitter($this->tenant, $mock))->submit($note);

        $this->assertInstanceOf(DeliveryMark::class, $mark);
        $this->assertSame('INSERT', $mark->mydata_action);
        $this->assertSame('480301204040191', $mark->mark);
        $this->assertStringContainsString('TimologioQR', (string) $mark->invoice_url);
        $this->assertStringContainsString('<invoiceType>9.3</invoiceType>', (string) $mark->request);

        // delivery_marks audit row exists.
        $this->assertDatabaseHas('delivery_marks', [
            'delivery_note_id' => $note->id,
            'mark' => '480301204040191',
            'mydata_action' => 'INSERT',
        ]);

        // The note's guarded cache columns reflect the filing.
        $fresh = $note->fresh();
        $this->assertTrue((bool) $fresh->mydata_sent);
        $this->assertSame('VALID', $fresh->mydata_state);
        $this->assertSame('480301204040191', $fresh->mydata_mark);
        $this->assertSame('registered', $fresh->delivery_state);
        $this->assertSame('active', $fresh->local_status);
        $this->assertStringContainsString('TimologioQR', (string) $fresh->mydata_url);
    }

    public function test_provider_tenant_files_delivery_note_through_provider_transport(): void
    {
        config()->set('ekdosi.einvoice.providers.fake-delivery', FakeDeliveryProviderTransport::class);

        $this->tenant->forceFill([
            'einvoice_provider' => 'gr-provider',
            'einvoice_provider_key' => 'fake-delivery',
            'einvoice_provider_mode' => 'sandbox',
            'einvoice_provider_config' => ['demo_base_url' => 'https://provider.test', 'demo_token' => 'tok'],
            'mydata_mode' => 'off',
        ])->save();

        $note = $this->makeNote();

        $mark = (new DeliveryNoteSubmitter($this->tenant->fresh()))->submit($note);

        $this->assertSame('PROVIDER_INSERT', $mark->mydata_action);
        $this->assertSame('fake-delivery', $mark->provider_key);
        $this->assertSame('400000000000777', $mark->mark);
        $this->assertSame('AUTH-DELIVERY', $mark->authentication_code);
        $this->assertStringContainsString('<invoiceType>9.3</invoiceType>', (string) $mark->request);

        $fresh = $note->fresh();
        $this->assertSame('VALID', $fresh->mydata_state);
        $this->assertSame('400000000000777', $fresh->mydata_mark);
        $this->assertSame('registered', $fresh->delivery_state);
        $this->assertSame('active', $fresh->local_status);
    }

    public function test_provider_delivery_note_stamps_a_backdated_issue_date_to_today(): void
    {
        // PROV-020 (auto): provider online issue requires today's date (238) and the
        // movement issue date is «now, when we send». A backdated note is stamped to
        // today and filed, instead of being blocked (mirrors the invoice path).
        config()->set('ekdosi.einvoice.providers.fake-delivery', FakeDeliveryProviderTransport::class);

        $this->tenant->forceFill([
            'einvoice_provider' => 'gr-provider',
            'einvoice_provider_key' => 'fake-delivery',
            'einvoice_provider_mode' => 'sandbox',
            'einvoice_provider_config' => ['demo_base_url' => 'https://provider.test', 'demo_token' => 'tok'],
            'mydata_mode' => 'off',
        ])->save();

        $note = $this->makeNote(['issued_at' => now()->subDay()->setTime(9, 0)]);

        $mark = (new DeliveryNoteSubmitter($this->tenant->fresh()))->submit($note);

        $today = now()->setTimezone(ProviderIssueDateGuard::TZ)->toDateString();

        $fresh = $note->fresh();
        $this->assertSame('VALID', $fresh->mydata_state);
        $this->assertSame($today, $fresh->issued_at->setTimezone(ProviderIssueDateGuard::TZ)->toDateString(),
            'issued_at is moved to today at send');
        // The OUTBOUND XML must carry today too — the stamp has to run BEFORE the
        // payload is serialized, not after (the exact ordering bug this guards).
        $this->assertStringContainsString("<issueDate>{$today}</issueDate>", (string) $mark->request,
            'the serialized IssueDate must be today, not the stale draft date');
    }

    public function test_provider_rejection_is_visible_in_delivery_history_with_request_and_response(): void
    {
        config()->set('ekdosi.einvoice.providers.fake-rejecting-delivery', FakeRejectingDeliveryProviderTransport::class);

        $this->tenant->forceFill([
            'einvoice_provider' => 'gr-provider',
            'einvoice_provider_key' => 'fake-rejecting-delivery',
            'einvoice_provider_mode' => 'sandbox',
            'einvoice_provider_config' => ['demo_base_url' => 'https://provider.test', 'demo_token' => 'tok'],
            'mydata_mode' => 'off',
        ])->save();

        $note = $this->makeNote();

        try {
            (new DeliveryNoteSubmitter($this->tenant->fresh()))->submit($note);
            $this->fail('expected provider rejection');
        } catch (DeliveryNoteRejected $e) {
            $this->assertStringContainsString('[88-004]', $e->getMessage());
        }

        $row = DeliveryMark::where('delivery_note_id', $note->id)->where('mydata_action', 'PROVIDER_REJECTED')->first();
        $this->assertNotNull($row);
        $this->assertSame('fake-rejecting-delivery', $row->provider_key);
        $this->assertStringContainsString('<invoiceType>9.3</invoiceType>', (string) $row->request);
        $this->assertStringContainsString('Missing or wrong xmlns:n1', (string) $row->response);
        $this->assertNull($note->fresh()->mydata_state);
    }

    public function test_rejected_submission_persists_rejected_row_and_throws(): void
    {
        $note = $this->makeNote();

        $mock = new MockHandler([
            new GuzzleResponse(200, [], $this->validationErrorXml()),
        ]);

        try {
            (new DeliveryNoteSubmitter($this->tenant, $mock))->submit($note);
            $this->fail('expected DeliveryNoteRejected on a non-Success response');
        } catch (DeliveryNoteRejected $e) {
            $this->assertStringContainsString('rejected', $e->getMessage());
            $this->assertStringContainsString('<invoiceType>', $e->requestXml);
            $this->assertStringContainsString('ValidationError', $e->responseXml);
        }

        // A forensic REJECTED row is persisted (visible in the δελτίο «Ιστορικό»).
        $this->assertDatabaseHas('delivery_marks', [
            'delivery_note_id' => $note->id,
            'mydata_action' => 'REJECTED',
            'mark' => null,
        ]);
        // The note is NOT marked filed.
        $this->assertNull($note->fresh()->mydata_state);
    }

    /* ============ Gapless-at-send Phase 2: assign at send / release on reject ============ */

    /** A provisional draft with no code — the new create flow. */
    private function makeProvisionalNote(array $overrides = []): DeliveryNote
    {
        return $this->makeNote(array_merge(['code' => null, 'invcode' => null], $overrides));
    }

    public function test_submit_assigns_the_real_number_before_transmission(): void
    {
        // A provisional draft (no ΑΑ) gets its real number reserved at the submit
        // choke-point; the type counter advances only then, so nothing burned it earlier.
        $note = $this->makeProvisionalNote();
        $this->assertNull($note->code);
        $this->assertTrue(ProvisionalCode::is($note->invcode));

        $mock = new MockHandler([new GuzzleResponse(200, [], $this->successResponseXml())]);
        (new DeliveryNoteSubmitter($this->tenant, $mock))->submit($note);

        $fresh = $note->fresh();
        $this->assertSame(1, (int) $fresh->code, 'real ΑΑ allocated at transmission');
        $this->assertSame('DA1', $fresh->invcode);
        $this->assertSame('DA', $fresh->series, 'series frozen at send');
        $this->assertSame('VALID', $fresh->mydata_state);
        $this->assertSame(2, (int) $this->deliveryType->fresh()->invcount, 'counter advanced once, at send');
    }

    public function test_a_definitive_rejection_releases_the_reserved_number(): void
    {
        // AADE processed and refused it → no MARK. The reserved ΑΑ returns to the pool
        // (revert to provisional) so the next attempt re-allocates — no gap in the sequence.
        $note = $this->makeProvisionalNote();
        $mock = new MockHandler([new GuzzleResponse(200, [], $this->validationErrorXml())]);

        try {
            (new DeliveryNoteSubmitter($this->tenant, $mock))->submit($note);
            $this->fail('expected DeliveryNoteRejected');
        } catch (DeliveryNoteRejected) {
            // expected
        }

        $fresh = $note->fresh();
        $this->assertNull($fresh->code, 'reverted to provisional after a definitive rejection');
        $this->assertTrue(ProvisionalCode::is($fresh->invcode));
        $this->assertNull($fresh->series);
        $this->assertSame(1, (int) $this->deliveryType->fresh()->invcount, 'reserved ΑΑ returned — no gap');
    }

    public function test_a_build_failure_after_assign_releases_the_reserved_number(): void
    {
        // A LOCAL build error (a blank mandatory address) fires AFTER the number is
        // reserved but before anything is transmitted: it must return the ΑΑ to the pool,
        // never burn one on a data/config error.
        $note = $this->makeProvisionalNote(['delivery_city' => '']);

        try {
            (new DeliveryNoteSubmitter($this->tenant))->submit($note);
            $this->fail('expected a build failure');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('διεύθυνση παράδοσης', $e->getMessage());
        }

        $fresh = $note->fresh();
        $this->assertNull($fresh->code, 'reverted to provisional after a local build error');
        $this->assertTrue(ProvisionalCode::is($fresh->invcode));
        $this->assertSame(1, (int) $this->deliveryType->fresh()->invcount, 'no ΑΑ burned by a data error');
    }

    public function test_a_provider_rejection_releases_the_reserved_number(): void
    {
        config()->set('ekdosi.einvoice.providers.fake-rejecting-delivery', FakeRejectingDeliveryProviderTransport::class);
        $this->tenant->forceFill([
            'einvoice_provider' => 'gr-provider',
            'einvoice_provider_key' => 'fake-rejecting-delivery',
            'einvoice_provider_mode' => 'sandbox',
            'einvoice_provider_config' => ['demo_base_url' => 'https://provider.test', 'demo_token' => 'tok'],
            'mydata_mode' => 'off',
        ])->save();

        $note = $this->makeProvisionalNote();

        try {
            (new DeliveryNoteSubmitter($this->tenant->fresh()))->submit($note);
            $this->fail('expected provider rejection');
        } catch (DeliveryNoteRejected) {
            // expected
        }

        $fresh = $note->fresh();
        $this->assertNull($fresh->code, 'reverted to provisional after a provider rejection');
        $this->assertSame(1, (int) $this->deliveryType->fresh()->invcount, 'reserved ΑΑ returned to the pool');
    }

    public function test_a_rejection_does_not_renumber_an_alread_y_numbered_note(): void
    {
        // Review finding 1 (P0): a δελτίο can reach the submitter ALREADY numbered (one
        // finalized before go-live, or a legacy import). assignDelivery no-ops → false, so
        // a DEFINITIVE rejection must NOT release: stripping/renumbering a validly-issued
        // legal document is the one outcome worse than a gap.
        $note = $this->makeNote();                         // code=1, a real ΑΑ
        $this->deliveryType->forceFill(['invcount' => 9])->save(); // counter moved on

        $mock = new MockHandler([new GuzzleResponse(200, [], $this->validationErrorXml())]);
        try {
            (new DeliveryNoteSubmitter($this->tenant, $mock))->submit($note);
            $this->fail('expected DeliveryNoteRejected');
        } catch (DeliveryNoteRejected) {
            // expected
        }

        $fresh = $note->fresh();
        $this->assertSame(1, (int) $fresh->code, 'the pre-existing ΑΑ is preserved, not stripped');
        $this->assertSame('DA1', $fresh->invcode);
        $this->assertSame(9, (int) $this->deliveryType->fresh()->invcount, 'counter untouched');
    }

    public function test_an_ambiguous_failure_keeps_the_reserved_number(): void
    {
        // A 502 MAY have filed (TransmissionFailedException) — the number stays reserved
        // AND armed so the in-doubt recovery adopts the real MARK by (series, ΑΑ) instead
        // of burning a duplicate. Releasing here would risk a second AADE document.
        $note = $this->makeProvisionalNote();
        $mock = new MockHandler([new GuzzleResponse(502, [], 'Bad Gateway')]);

        try {
            (new DeliveryNoteSubmitter($this->tenant, $mock))->submit($note);
            $this->fail('expected the transmission failure to throw');
        } catch (\Throwable) {
            // expected
        }

        $fresh = $note->fresh();
        $this->assertSame(1, (int) $fresh->code, 'number KEPT on an ambiguous failure');
        $this->assertNotNull($fresh->mydata_pending_since, 'stays armed for in-doubt recovery');
        $this->assertSame(2, (int) $this->deliveryType->fresh()->invcount, 'counter not rolled back');
    }

    private function validationErrorXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<ResponseDoc xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema">
    <response>
        <statusCode>ValidationError</statusCode>
        <errors>
            <error>
                <message>Test validation error</message>
                <code>205</code>
            </error>
        </errors>
    </response>
</ResponseDoc>
XML;
    }

    public function test_submit_refuses_already_filed_note(): void
    {
        $note = $this->makeNote();
        $note->forceFill(['mydata_state' => 'VALID', 'mydata_mark' => '480301204040191'])->save();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/already filed at myDATA/');

        (new DeliveryNoteSubmitter($this->tenant))->submit($note->fresh());
    }

    /** Mirrors firebed's stubs/send-invoices-single-response.xml. */
    private function successResponseXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<ResponseDoc xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema">
    <response>
        <index>1</index>
        <invoiceUid>6F825B9B74717280D1F9A38252D0B65604C6D162</invoiceUid>
        <invoiceMark>480301204040191</invoiceMark>
        <qrUrl>https://mydataapidev.aade.gr/TimologioQR/QRInfo?q=testqr</qrUrl>
        <statusCode>Success</statusCode>
    </response>
</ResponseDoc>
XML;
    }
}

/** Provider transport fake for delivery-note submitter coverage — no network. */
class FakeDeliveryProviderTransport implements EInvoiceProviderTransport
{
    public function key(): string
    {
        return 'fake-delivery';
    }

    public function send(Invoice $invoice, string $documentXml, ProviderCredentials $credentials): ProviderResult
    {
        return ProviderResult::failed(['not used in this delivery test']);
    }

    public function sendDelivery(DeliveryNote $note, string $documentXml, ProviderCredentials $credentials): ProviderResult
    {
        return ProviderResult::ok(
            mark: '400000000000777',
            uid: 'DELIVERY-UID',
            authenticationCode: 'AUTH-DELIVERY',
            qrUrl: 'https://provider.test/qr/777',
            raw: '<provider-response/>',
            requestPayload: $documentXml,
        );
    }

    public function cancel(string $mark, ProviderCredentials $credentials, string $reason = ''): ProviderResult
    {
        return ProviderResult::ok(cancellationMark: '400000000000778');
    }

    public function status(Invoice $invoice, ProviderCredentials $credentials): ProviderResult
    {
        return ProviderResult::ok(mark: '400000000000777');
    }

    public function ping(ProviderCredentials $credentials): bool
    {
        return true;
    }
}

/** Rejecting provider fake for delivery-note history/debugging coverage. */
class FakeRejectingDeliveryProviderTransport implements EInvoiceProviderTransport
{
    public function key(): string
    {
        return 'fake-rejecting-delivery';
    }

    public function send(Invoice $invoice, string $documentXml, ProviderCredentials $credentials): ProviderResult
    {
        return ProviderResult::failed(['not used in this delivery test']);
    }

    public function sendDelivery(DeliveryNote $note, string $documentXml, ProviderCredentials $credentials): ProviderResult
    {
        return ProviderResult::failed(
            ['[88-004] Missing or wrong xmlns:n1'],
            '<ResponseDoc><response><statusCode>ValidationError</statusCode><errors><error><code>88-004</code><message>Missing or wrong xmlns:n1</message></error></errors></response></ResponseDoc>',
            $documentXml,
        );
    }

    public function cancel(string $mark, ProviderCredentials $credentials, string $reason = ''): ProviderResult
    {
        return ProviderResult::ok(cancellationMark: '400000000000779');
    }

    public function status(Invoice $invoice, ProviderCredentials $credentials): ProviderResult
    {
        return ProviderResult::ok(mark: '400000000000777');
    }

    public function ping(ProviderCredentials $credentials): bool
    {
        return true;
    }
}
