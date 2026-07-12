<?php

namespace Tests\Feature;

use App\DTOs\AadeRegistryRecord;
use App\Exceptions\Aade\AadeAfmNotFound;
use App\Models\Company;
use App\Models\Supplier;
use App\Services\AadeRegistryLookup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * `suppliers:backfill-names` repairs ΑΦΜ-only «αδέσποτοι» suppliers created
 * before the importer GSIS-enriched on create. Fill-only-empty (operator edits
 * survive), best-effort (a GSIS miss leaves the row nameless, doesn't abort).
 */
class SuppliersBackfillNamesTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Company::create([
            'name' => 'Backfill test',
            'slug' => 'backfill-'.uniqid(),
            'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata',
            'mydata_mode' => 'sandbox',
            'afm' => '801280908',
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_fills_names_for_nameless_greek_suppliers_and_leaves_others_alone(): void
    {
        // Nameless GR supplier (the bug's leftover) — must be filled.
        $nameless = Supplier::create([
            'company_id' => $this->tenant->id,
            'afm' => '094468339',
            'country' => 'GR',
            'source' => 'sync',
            'is_active' => true,
        ]);

        // Already-named GR supplier — must be left untouched (not re-enriched).
        $named = Supplier::create([
            'company_id' => $this->tenant->id,
            'afm' => '998482379',
            'name' => 'Χειροκίνητος',
            'country' => 'GR',
            'source' => 'manual',
        ]);

        $lookup = Mockery::mock(AadeRegistryLookup::class);
        // Only the nameless AFM is ever looked up.
        $lookup->shouldReceive('findByAfm')->with('094468339')->once()->andReturn(
            new AadeRegistryRecord(
                afm: '094468339',
                name: 'AEGEAN AIRLINES A.E.',
                doy: 'ΦΑΕ ΑΘΗΝΩΝ',
                doyCode: '1159',
                active: true,
                statusDescr: 'ΕΝΕΡΓΟΣ ΑΦΜ',
                address: 'ΒΙΛΤΑΝΙΩΤΗ 31',
                city: 'ΚΗΦΙΣΙΑ',
                postcode: '14564',
                activities: [['code' => '51100000', 'description' => 'ΑΕΡΟΠΟΡΙΚΕΣ ΜΕΤΑΦΟΡΕΣ', 'kind' => 'KYRIA']],
            )
        );
        $this->app->bind(AadeRegistryLookup::class, fn ($app, $params) => $lookup);

        $this->artisan('suppliers:backfill-names', ['--tenant' => $this->tenant->slug])
            ->assertSuccessful();

        $nameless->refresh();
        $this->assertSame('AEGEAN AIRLINES A.E.', $nameless->name);
        $this->assertSame('ΦΑΕ ΑΘΗΝΩΝ', $nameless->tax_office);
        $this->assertSame('ΚΗΦΙΣΙΑ', $nameless->city);

        $named->refresh();
        $this->assertSame('Χειροκίνητος', $named->name);
    }

    public function test_gsis_failure_leaves_supplier_nameless(): void
    {
        $supplier = Supplier::create([
            'company_id' => $this->tenant->id,
            'afm' => '094468339',
            'country' => 'GR',
            'source' => 'sync',
        ]);

        $lookup = Mockery::mock(AadeRegistryLookup::class);
        $lookup->shouldReceive('findByAfm')->andThrow(new AadeAfmNotFound('nope'));
        $this->app->bind(AadeRegistryLookup::class, fn ($app, $params) => $lookup);

        $this->artisan('suppliers:backfill-names', ['--tenant' => $this->tenant->slug])
            ->assertSuccessful();

        $supplier->refresh();
        $this->assertNull($supplier->name);
    }
}
