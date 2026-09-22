<?php

namespace Tests\Feature\Whmcs;

use App\DTOs\AadeRegistryRecord;
use App\Exceptions\Aade\AadeAfmNotFound;
use App\Models\Company;
use App\Models\Customer;
use App\Models\PendingWhmcsInvoice;
use App\Services\AadeRegistryLookup;
use App\Services\Whmcs\WhmcsCustomerCreator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

/**
 * Slice 3: create the ekdosi customer from a staged WHMCS invoice's ΑΦΜ —
 * GSIS-authoritative, WHMCS-typed data as fallback, idempotent by ΑΦΜ, and the
 * operator-confirmed whmcs_client_id link. GSIS is mocked.
 */
class WhmcsCustomerCreatorTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(): Company
    {
        return Company::create([
            'name' => 'T', 'slug' => 'cc-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
            'whmcs_custom_field_map' => ['vatno' => 13, 'taxoffice' => 14, 'occupation' => 15],
        ]);
    }

    private function pending(Company $t, ?string $afm, int $userId = 793): PendingWhmcsInvoice
    {
        $customfields = [];
        if ($afm !== null) {
            $customfields[] = ['id' => 13, 'value' => $afm];
        }

        return PendingWhmcsInvoice::create([
            'company_id' => $t->id,
            'whmcs_invoice_id' => random_int(1, 99999),
            'whmcs_userid' => $userId,
            'payload' => [
                'companyname' => 'ACME WHMCS OE', 'address1' => 'Οδός 1', 'city' => 'Αθήνα',
                'postcode' => '11111', 'country' => 'GR', 'email' => 'a@e.test',
                'customfields' => $customfields,
            ],
            'match_reason' => PendingWhmcsInvoice::REASON_UNMATCHED,
            'status' => PendingWhmcsInvoice::STATUS_PENDING_REVIEW,
        ]);
    }

    private function mockGsis(?AadeRegistryRecord $record, ?\Throwable $throw = null): void
    {
        $mock = Mockery::mock(AadeRegistryLookup::class);
        $expectation = $mock->shouldReceive('findByAfm');
        $throw !== null ? $expectation->andThrow($throw) : $expectation->andReturn($record);
        $this->app->bind(AadeRegistryLookup::class, fn () => $mock);
    }

    private function aadeRecord(): AadeRegistryRecord
    {
        return new AadeRegistryRecord(
            afm: '123456789', name: 'ΟΦΙΣΙΑΛ ΑΑΔΕ ΕΠΕ', doy: 'Α ΑΘΗΝΩΝ', doyCode: '1101',
            active: true, statusDescr: 'ΕΝΕΡΓΟΣ', address: 'Σταδίου 10', city: 'Αθήνα', postcode: '10564',
            activities: [['code' => '62.01', 'description' => 'Προγραμματισμός Η/Υ', 'kind' => 'κύρια']],
        );
    }

    /** A tenant whose WHMCS field-map also carries the «γκρινιάρης» role (id 16). */
    private function tenantWithGriniaris(): Company
    {
        return Company::create([
            'name' => 'T', 'slug' => 'cc-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
            'whmcs_custom_field_map' => ['vatno' => 13, 'griniaris' => 16],
        ]);
    }

    /** $griniaris null = the checkbox is absent/unset in WHMCS. */
    private function pendingWithGriniaris(Company $t, string $afm, ?string $griniaris): PendingWhmcsInvoice
    {
        $customfields = [['id' => 13, 'value' => $afm]];
        if ($griniaris !== null) {
            $customfields[] = ['id' => 16, 'value' => $griniaris];
        }

        return PendingWhmcsInvoice::create([
            'company_id' => $t->id,
            'whmcs_invoice_id' => random_int(1, 99999),
            'whmcs_userid' => 793,
            'payload' => [
                'companyname' => 'ACME WHMCS OE', 'country' => 'GR',
                'customfields' => $customfields,
            ],
            'match_reason' => PendingWhmcsInvoice::REASON_UNMATCHED,
            'status' => PendingWhmcsInvoice::STATUS_PENDING_REVIEW,
        ]);
    }

    public function test_creates_from_aade_when_afm_resolves(): void
    {
        $t = $this->tenant();
        $row = $this->pending($t, '123456789');
        $this->mockGsis($this->aadeRecord());

        $result = app(WhmcsCustomerCreator::class)->createForPending($t, $row);

        $this->assertTrue($result->created);
        $this->assertSame('aade', $result->source);
        $c = $result->customer;
        $this->assertSame('123456789', $c->afm);
        $this->assertSame('ΟΦΙΣΙΑΛ ΑΑΔΕ ΕΠΕ', $c->name);          // AADE name wins over WHMCS
        $this->assertSame('Α ΑΘΗΝΩΝ', $c->tax_office);
        $this->assertSame('Σταδίου 10', $c->address1);
        $this->assertSame('Προγραμματισμός Η/Υ', $c->occupation);
        $this->assertSame('a@e.test', $c->email);                 // email always from WHMCS
        $this->assertSame(793, $c->whmcs_client_id);              // operator-confirmed link
    }

    /** A resolve.php third-party contact array. */
    private function contact(?string $afm, array $over = []): array
    {
        return array_merge([
            'id' => 5, 'company_name' => 'ΤΡΙΤΟΣ ΟΕ', 'gr_vatno' => $afm,
            'vies_vatno' => '', 'tax_office' => 'ΚΑΒΑΛΑΣ', 'address1' => 'Οδός Τρίτου 2',
            'address2' => '', 'city' => 'Καβάλα', 'postal_code' => '65000', 'country' => 'GR',
            'description' => 'ΛΕΥΚΑ ΕΙΔΗ', 'email' => 'third@e.test', 'telephone' => '2510000000',
        ], $over);
    }

    public function test_create_from_contact_uses_aade_and_keeps_contact_email_and_referral(): void
    {
        $t = $this->tenant();
        $this->mockGsis($this->aadeRecord());
        $reseller = Customer::create(['company_id' => $t->id, 'name' => 'Reseller', 'afm' => '700700700']);

        $result = app(WhmcsCustomerCreator::class)->createFromContact($t, $this->contact('123456789'), $reseller->id);

        $this->assertTrue($result->created);
        $this->assertSame('aade', $result->source);
        $c = $result->customer;
        $this->assertSame('123456789', $c->afm);
        $this->assertSame('ΟΦΙΣΙΑΛ ΑΑΔΕ ΕΠΕ', $c->name);            // AADE wins over the contact
        $this->assertSame('Α ΑΘΗΝΩΝ', $c->tax_office);
        $this->assertSame('third@e.test', $c->email);               // contact email kept (GSIS has none)
        $this->assertSame($reseller->id, $c->referred_by_customer_id);  // «συστήθηκε από» ο reseller
        $this->assertNull($c->whmcs_client_id);                     // a third party is NOT the WHMCS client
    }

    public function test_create_from_contact_falls_back_to_contact_fields_when_gsis_fails(): void
    {
        $t = $this->tenant();
        $this->mockGsis(null, new AadeAfmNotFound('x'));

        $result = app(WhmcsCustomerCreator::class)->createFromContact($t, $this->contact('999999998'));

        $this->assertTrue($result->created);
        $this->assertSame('whmcs', $result->source);
        $c = $result->customer;
        $this->assertSame('ΤΡΙΤΟΣ ΟΕ', $c->name);
        $this->assertSame('ΚΑΒΑΛΑΣ', $c->tax_office);
        $this->assertSame('Καβάλα', $c->city);
        $this->assertSame('third@e.test', $c->email);
        $this->assertNull($c->referred_by_customer_id);
    }

    public function test_create_from_contact_without_afm_returns_no_afm(): void
    {
        $t = $this->tenant();

        $result = app(WhmcsCustomerCreator::class)->createFromContact($t, $this->contact(null));

        $this->assertNull($result->customer);
        $this->assertSame('no_afm', $result->source);
        $this->assertSame(0, Customer::where('company_id', $t->id)->count());
    }

    public function test_create_from_contact_is_idempotent_and_gap_fills_existing(): void
    {
        $t = $this->tenant();
        $reseller = Customer::create(['company_id' => $t->id, 'name' => 'Reseller', 'afm' => '700700700']);
        $existing = Customer::create(['company_id' => $t->id, 'name' => 'ΤΡΙΤΟΣ ΟΕ', 'afm' => '123456789']);
        $this->mockGsis($this->aadeRecord());   // not consumed — found by ΑΦΜ first

        $result = app(WhmcsCustomerCreator::class)->createFromContact($t, $this->contact('123456789'), $reseller->id);

        $this->assertFalse($result->created);
        $this->assertSame('existing', $result->source);
        $this->assertSame($existing->id, $result->customer->id);
        $existing->refresh();
        $this->assertSame('third@e.test', $existing->email);                  // gap-filled
        $this->assertSame($reseller->id, $existing->referred_by_customer_id); // gap-filled
    }

    public function test_create_from_contact_caps_a_long_occupation(): void
    {
        // GSIS unavailable → occupation falls back to the reseller-typed contact
        // `description`, which is free text and must be capped to the column width.
        $t = $this->tenant();
        $this->mockGsis(null, new AadeAfmNotFound('x'));

        app(WhmcsCustomerCreator::class)
            ->createFromContact($t, $this->contact('999999998', ['description' => str_repeat('Δ', 200)]));

        $c = Customer::where('company_id', $t->id)->first();
        $this->assertNotNull($c);
        $this->assertLessThanOrEqual(120, mb_strlen((string) $c->occupation));
    }

    public function test_create_from_contact_never_overwrites_an_existing_email(): void
    {
        $t = $this->tenant();
        $existing = Customer::create([
            'company_id' => $t->id, 'name' => 'ΤΡΙΤΟΣ', 'afm' => '123456789', 'email' => 'kept@e.test',
        ]);
        $this->mockGsis($this->aadeRecord());

        app(WhmcsCustomerCreator::class)->createFromContact($t, $this->contact('123456789', ['email' => 'new@e.test']));

        $existing->refresh();
        $this->assertSame('kept@e.test', $existing->email);   // never overwritten
    }

    public function test_falls_back_to_whmcs_data_when_gsis_fails(): void
    {
        $t = $this->tenant();
        // (999999999 is an all-same-digit PLACEHOLDER — not an identity — so a
        // real-looking ΑΦΜ is used here.)
        $row = $this->pending($t, '999999998');
        $this->mockGsis(null, new AadeAfmNotFound('AFM not found'));

        $result = app(WhmcsCustomerCreator::class)->createForPending($t, $row);

        $this->assertTrue($result->created);
        $this->assertSame('whmcs', $result->source);
        $this->assertSame('ACME WHMCS OE', $result->customer->name);   // WHMCS name
        $this->assertSame('999999998', $result->customer->afm);
        $this->assertSame('Οδός 1', $result->customer->address1);
    }

    public function test_returns_existing_and_links_it(): void
    {
        $t = $this->tenant();
        $existing = Customer::create(['company_id' => $t->id, 'name' => 'Ήδη Υπάρχων', 'afm' => '123456789']);
        $row = $this->pending($t, '123456789', userId: 555);
        // No GSIS call expected (we short-circuit on the existing ΑΦΜ).

        $result = app(WhmcsCustomerCreator::class)->createForPending($t, $row);

        $this->assertFalse($result->created);
        $this->assertSame('existing', $result->source);
        $this->assertSame($existing->id, $result->customer->id);
        $this->assertSame(555, $existing->fresh()->whmcs_client_id);   // link stamped
    }

    public function test_foreign_vat_matches_the_existing_customer_by_identity(): void
    {
        $t = $this->tenant();
        $cy = Customer::create(['company_id' => $t->id, 'name' => 'Κύπριος', 'afm' => 'CY10259033P']);
        $row = $this->pending($t, 'cy 10259033 p', userId: 557);

        $result = app(WhmcsCustomerCreator::class)->createForPending($t, $row);

        $this->assertSame('existing', $result->source);
        $this->assertSame($cy->id, $result->customer->id, 'letters kept — never a digits-only twin');
        $this->assertSame(1, Customer::withTrashed()->where('company_id', $t->id)->count());
    }

    public function test_losing_the_create_race_returns_the_winner_as_existing(): void
    {
        $t = $this->tenant();
        $row = $this->pending($t, '123456789', userId: 558);
        $this->mockGsis(null, new AadeAfmNotFound('AFM not found'));

        $fired = false;
        Customer::creating(function (Customer $c) use (&$fired, $t): void {
            if (! $fired) {
                $fired = true;
                DB::table('customers')->insert(['company_id' => $t->id, 'name' => 'Νικητής', 'afm' => '123456789', 'afm_key' => '123456789', 'created_at' => now(), 'updated_at' => now()]);
            }
        });

        $result = app(WhmcsCustomerCreator::class)->createForPending($t, $row);

        $this->assertFalse($result->created);
        $this->assertSame('existing', $result->source);
        $this->assertSame('Νικητής', $result->customer->name);
        $this->assertSame(1, Customer::withTrashed()->where('company_id', $t->id)->count());
        $this->assertSame(558, $result->customer->fresh()->whmcs_client_id, 'the winner is linked exactly like an owner found up-front');
    }

    public function test_deleted_owner_is_reported_not_recreated(): void
    {
        $t = $this->tenant();
        $deleted = Customer::create(['company_id' => $t->id, 'name' => 'Σβησμένος', 'afm' => '123456789']);
        $deleted->delete();
        $row = $this->pending($t, 'EL 123456789', userId: 556);

        $result = app(WhmcsCustomerCreator::class)->createForPending($t, $row);

        $this->assertFalse($result->created);
        $this->assertSame('deleted_owner', $result->source);
        $this->assertSame($deleted->id, $result->customer->id);
        $this->assertNull($deleted->fresh()->whmcs_client_id, 'nothing linked on a deleted owner');
        $this->assertSame(1, Customer::withTrashed()->where('company_id', $t->id)->count(), 'no second customer for the ΑΦΜ');
    }

    public function test_no_afm_returns_no_afm(): void
    {
        $t = $this->tenant();
        $row = $this->pending($t, null);

        $result = app(WhmcsCustomerCreator::class)->createForPending($t, $row);

        $this->assertNull($result->customer);
        $this->assertSame('no_afm', $result->source);
        $this->assertSame(0, Customer::where('company_id', $t->id)->count());
    }

    public function test_uses_afm_override_when_given(): void
    {
        $t = $this->tenant();
        $row = $this->pending($t, null);   // no ΑΦΜ in WHMCS — operator types one
        $this->mockGsis($this->aadeRecord());

        $result = app(WhmcsCustomerCreator::class)->createForPending($t, $row, 'EL123456789');

        $this->assertTrue($result->created);
        $this->assertSame('aade', $result->source);
        $this->assertSame('123456789', $result->customer->afm);   // EL stripped, override used
    }

    public function test_pulls_phone_from_whmcs_payload(): void
    {
        $t = $this->tenant();
        $row = $this->pending($t, '123456789');
        $row->update(['payload' => array_merge($row->payload, ['phonenumber' => '2101234567'])]);
        $this->mockGsis($this->aadeRecord());

        $result = app(WhmcsCustomerCreator::class)->createForPending($t, $row);

        $this->assertSame('2101234567', $result->customer->phone1);   // phone from WHMCS (GSIS has none)
    }

    public function test_reports_discrepancies_when_aade_differs_from_whmcs(): void
    {
        $t = $this->tenant();
        // WHMCS company name «ACME WHMCS OE» differs from the official GSIS name.
        $row = $this->pending($t, '123456789');
        $this->mockGsis($this->aadeRecord());

        $result = app(WhmcsCustomerCreator::class)->createForPending($t, $row);

        $name = collect($result->discrepancies)->firstWhere('field', 'Επωνυμία');
        $this->assertNotNull($name, 'name discrepancy reported');
        $this->assertSame('ACME WHMCS OE', $name['whmcs']);
        $this->assertSame('ΟΦΙΣΙΑΛ ΑΑΔΕ ΕΠΕ', $name['aade']);
        $this->assertSame('ΟΦΙΣΙΑΛ ΑΑΔΕ ΕΠΕ', $result->customer->name);   // official value kept
    }

    public function test_no_discrepancies_when_gsis_unavailable(): void
    {
        $t = $this->tenant();
        $row = $this->pending($t, '999999999');
        $this->mockGsis(null, new AadeAfmNotFound('x'));

        $result = app(WhmcsCustomerCreator::class)->createForPending($t, $row);

        $this->assertSame([], $result->discrepancies);   // no GSIS → nothing to compare
    }

    public function test_seeds_immediate_invoice_flag_from_griniaris_on_create(): void
    {
        $t = $this->tenantWithGriniaris();
        $row = $this->pendingWithGriniaris($t, '123456789', 'on');
        $this->mockGsis($this->aadeRecord());

        $result = app(WhmcsCustomerCreator::class)->createForPending($t, $row);

        $this->assertTrue($result->created);
        $this->assertTrue($result->customer->needs_immediate_invoice, 'γκρινιάρης=on seeds άμεση τιμολόγηση at create');
    }

    public function test_immediate_invoice_flag_defaults_off_without_griniaris(): void
    {
        $t = $this->tenantWithGriniaris();
        $row = $this->pendingWithGriniaris($t, '123456789', null);   // checkbox absent/unset
        $this->mockGsis($this->aadeRecord());

        $result = app(WhmcsCustomerCreator::class)->createForPending($t, $row);

        $this->assertTrue($result->created);
        $this->assertFalse($result->customer->needs_immediate_invoice, 'no γκρινιάρης → flag stays off, never forced');
    }

    public function test_existing_customer_immediate_invoice_flag_is_not_overwritten(): void
    {
        $t = $this->tenantWithGriniaris();
        // The operator has DELIBERATELY set this existing customer OFF for άμεση.
        $existing = Customer::create([
            'company_id' => $t->id, 'name' => 'Ήδη Υπάρχων', 'afm' => '123456789',
            'needs_immediate_invoice' => false,
        ]);
        $row = $this->pendingWithGriniaris($t, '123456789', 'on');   // WHMCS now says γκρινιάρης
        // No GSIS mock: the existing-ΑΦΜ short-circuit never reaches the registry.

        $result = app(WhmcsCustomerCreator::class)->createForPending($t, $row);

        $this->assertFalse($result->created);
        $this->assertSame('existing', $result->source);
        // createForPending is a pure create/link — it never mutates an existing
        // customer's flag. (Flag PROPAGATION for existing customers is the ingestor
        // mirror's job, tested in WhmcsInvoiceIngestorTest — not this operator action.)
        $this->assertFalse(
            $existing->fresh()->needs_immediate_invoice,
            'createForPending on an existing ΑΦΜ leaves the flag untouched',
        );
    }

    public function test_existing_immediate_customer_is_not_turned_off_by_a_resync(): void
    {
        $t = $this->tenantWithGriniaris();
        // The operator has DELIBERATELY set this existing customer ON for άμεση.
        $existing = Customer::create([
            'company_id' => $t->id, 'name' => 'Ήδη Άμεσος', 'afm' => '123456789',
            'needs_immediate_invoice' => true,
        ]);
        // WHMCS no longer marks the client γκρινιάρης (field cleared).
        $row = $this->pendingWithGriniaris($t, '123456789', null);

        $result = app(WhmcsCustomerCreator::class)->createForPending($t, $row);

        $this->assertFalse($result->created);
        $this->assertSame('existing', $result->source);
        // Both directions confirm createForPending is flag-NEUTRAL on existing rows
        // (never on→off here, never off→on above). System-wide propagation — including
        // WHMCS turning it OFF as the source of truth — is the ingestor mirror's job,
        // covered in WhmcsInvoiceIngestorTest; this operator create/link never does it.
        $this->assertTrue(
            $existing->fresh()->needs_immediate_invoice,
            'createForPending on an existing ΑΦΜ leaves the flag untouched (both directions)',
        );
    }
}
