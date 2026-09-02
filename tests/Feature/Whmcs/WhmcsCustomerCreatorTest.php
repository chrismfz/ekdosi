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

    public function test_falls_back_to_whmcs_data_when_gsis_fails(): void
    {
        $t = $this->tenant();
        $row = $this->pending($t, '999999999');
        $this->mockGsis(null, new AadeAfmNotFound('AFM not found'));

        $result = app(WhmcsCustomerCreator::class)->createForPending($t, $row);

        $this->assertTrue($result->created);
        $this->assertSame('whmcs', $result->source);
        $this->assertSame('ACME WHMCS OE', $result->customer->name);   // WHMCS name
        $this->assertSame('999999999', $result->customer->afm);
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
}
