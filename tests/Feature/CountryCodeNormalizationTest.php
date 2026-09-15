<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\PaymentMethod;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * MYD-011 (Option B): the normalised `country_code` cache on customers/suppliers.
 *
 * The load-bearing guarantees: (1) the free-text country never breaks a save (the
 * picker binds to the clean cache, not «ΙΤΑΛΙΑ»); (2) a foreign supplier/customer is
 * never frozen as GR; (3) reading is a zero-regression superset of the old
 * `IsoCountry::tryNormalise(country)` path.
 */
class CountryCodeNormalizationTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Company::create([
            'name' => 'CC OE', 'slug' => 'cc-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off', 'afm' => '800561849',
        ]);
    }

    private function customer(array $overrides = []): Customer
    {
        static $n = 0;
        $n++;

        return Customer::create(array_merge([
            'company_id' => $this->tenant->id,
            'name' => 'Πελ '.$n,
            'afm' => '80056184'.($n % 10),
        ], $overrides));
    }

    public function test_saving_derives_the_iso_code_from_free_text_country(): void
    {
        $this->assertSame('IT', $this->customer(['country' => 'ΙΤΑΛΙΑ'])->country_code);
        $this->assertSame('DE', $this->customer(['country' => 'Germany'])->country_code);
        $this->assertSame('GR', $this->customer(['country' => 'EL'])->country_code); // VAT prefix
        $this->assertNull($this->customer(['country' => null])->country_code);
        $this->assertNull($this->customer(['country' => 'Neverland'])->country_code); // unresolvable → null, no guess
    }

    public function test_picker_path_mirrors_the_code_into_a_blank_country(): void
    {
        // The operator picks a country_code and never types the free-text label; the
        // hook mirrors it so every downstream reader of `country` keeps resolving.
        $c = $this->customer(['country_code' => 'IT']);
        $this->assertSame('IT', $c->country_code);
        $this->assertSame('IT', $c->country);
    }

    public function test_clearing_the_picker_blanks_both_columns(): void
    {
        // The free-text field is hidden, so the picker is the only control — clearing
        // it must actually un-set the country, not silently re-derive from the mirror.
        $c = $this->customer(['country_code' => 'IT']);
        $this->assertSame('IT', $c->country);

        $c->update(['country_code' => null]);
        $c->refresh();
        $this->assertNull($c->country_code);
        $this->assertNull($c->country);
    }

    public function test_re_picking_overwrites_a_legacy_label(): void
    {
        // A legacy row keeps its «ΙΤΑΛΙΑ» label until the operator DELIBERATELY re-picks;
        // then the picker is authoritative and both columns follow it (no divergence).
        $c = $this->customer(['country' => 'ΙΤΑΛΙΑ']);
        $this->assertSame('IT', $c->country_code);
        $this->assertSame('ΙΤΑΛΙΑ', $c->country); // label preserved while the picker is untouched

        $c->update(['country_code' => 'FR']);
        $c->refresh();
        $this->assertSame('FR', $c->country_code);
        $this->assertSame('FR', $c->country);
    }

    public function test_editing_the_label_to_an_unresolvable_value_clears_the_code(): void
    {
        // Intentional + safe: if the label is changed to something unrecognised, the
        // derived code becomes null (→ the submitter refuses) rather than keeping a
        // stale code the new label no longer justifies.
        $c = $this->customer(['country' => 'Italy']);
        $this->assertSame('IT', $c->country_code);

        $c->update(['country' => 'Freedonia']);
        $c->refresh();
        $this->assertNull($c->country_code);
    }

    public function test_iso_accessor_falls_back_to_live_normalise_for_un_backfilled_rows(): void
    {
        // A row whose cache is still NULL (pre-migration) must resolve identically to
        // the old tryNormalise(country) path — the zero-regression guarantee.
        $c = $this->customer(['country' => 'Italy']);
        $c->forceFill(['country_code' => null])->saveQuietly(); // simulate un-backfilled
        $c->refresh();

        $this->assertNull($c->country_code);
        $this->assertSame('IT', $c->isoCountryCode());
    }

    public function test_supplier_is_never_frozen_as_greek_when_left_blank(): void
    {
        // The whole freeze bug: a foreign supplier left blank used to default to GR.
        $blank = Supplier::create(['company_id' => $this->tenant->id, 'name' => 'Ξένος', 'afm' => 'DE811234567']);
        $this->assertNull($blank->country);
        $this->assertNull($blank->country_code);
        $this->assertNull($blank->isoCountryCode()); // → the ΔΑ submitter REFUSES, never a silent GR

        $it = Supplier::create(['company_id' => $this->tenant->id, 'name' => 'Rossi', 'country_code' => 'IT']);
        $this->assertSame('IT', $it->isoCountryCode());
        $this->assertSame('IT', $it->country);
    }

    public function test_backfill_command_lights_up_legacy_rows_and_is_idempotent(): void
    {
        $legacy = $this->customer(['country' => 'ΙΤΑΛΙΑ']);
        $legacy->forceFill(['country_code' => null])->saveQuietly(); // pre-migration state
        $blank = $this->customer(['country' => null]);
        $supplier = Supplier::create(['company_id' => $this->tenant->id, 'name' => 'S', 'country' => 'Germany']);
        $supplier->forceFill(['country_code' => null])->saveQuietly();

        $this->artisan('ekdosi:backfill-country-codes')->assertSuccessful();
        $this->artisan('ekdosi:backfill-country-codes')->assertSuccessful(); // idempotent

        $this->assertSame('IT', $legacy->fresh()->country_code);
        $this->assertNull($blank->fresh()->country_code); // blank → left null, never guessed
        $this->assertSame('DE', $supplier->fresh()->country_code);
    }

    public function test_the_in_migration_data_backfill_normalises_legacy_rows(): void
    {
        // The migration backfills via DB::table (a different path than the command);
        // the schema half already ran, so re-invoke the data half on rows we control.
        $legacy = $this->customer(['country' => 'ΙΤΑΛΙΑ']);
        $legacy->forceFill(['country_code' => null])->saveQuietly();
        $neverland = $this->customer(['country' => 'Neverland']);
        $neverland->forceFill(['country_code' => null])->saveQuietly();

        $migration = require base_path(
            'tests/Fixtures/migrations/2026_09_08_000001_add_country_code_to_customers_and_suppliers.php'
        );
        $method = new ReflectionMethod($migration, 'backfill');
        $method->setAccessible(true);
        $method->invoke($migration, 'customers');

        $this->assertSame('IT', $legacy->fresh()->country_code);
        $this->assertNull($neverland->fresh()->country_code); // unresolvable → left null
    }

    public function test_delivery_note_and_invoice_resolvers_read_the_customer_cache(): void
    {
        $type = InvoiceType::create([
            'company_id' => $this->tenant->id, 'code' => 'TPY', 'name' => 'ΤΠΥ',
            'invcount' => 1, 'mydata_type' => '2.1',
        ]);
        $pm = PaymentMethod::create(['company_id' => $this->tenant->id, 'description' => 'Μετρητά', 'due_days' => 0]);
        $foreign = $this->customer(['country_code' => 'IT']);

        // Draft invoice with no country of its own → falls back to the live customer,
        // which now resolves through the cache.
        $inv = Invoice::create([
            'company_id' => $this->tenant->id, 'invcode' => 'I'.uniqid(), 'code' => 1,
            'invoice_type_id' => $type->id, 'payment_method_id' => $pm->id, 'customer_id' => $foreign->id,
            'issued_at' => now(), 'local_status' => 'draft', 'country' => null,
            'net_total' => 100, 'gross_total' => 124, 'header_discount_percent' => 0,
        ]);
        $this->assertSame('IT', $inv->counterpartCountryIso());
    }
}
