<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\InvoiceType;
use App\Services\InvoiceAllocation;
use App\Services\InvoiceNumberer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * Sequential correctness tests for InvoiceNumberer. Run on the default
 * test driver (sqlite :memory: per phpunit.xml) — these tests don't
 * exercise concurrent row locking, so the driver choice doesn't matter.
 *
 * The actual concurrent hammer lives in
 * `php artisan test:invoice-numbering-concurrent` (artisan command)
 * because it requires (a) real MariaDB row locks and (b) forked
 * processes that don't play well with PHPUnit's transaction wrapping.
 */
class InvoiceNumbererTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private InvoiceType $apyType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'name' => 'Test Co',
            'slug' => 'test-co-'.uniqid(),
            'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata',
            'mydata_mode' => 'off',
        ]);

        $this->apyType = InvoiceType::create([
            'company_id' => $this->company->id,
            'code' => 'APY',
            'name' => 'Απόδειξη Παροχής Υπηρεσιών',
            'invcount' => 1,
        ]);
    }

    public function test_allocate_returns_invcode_in_legacy_format(): void
    {
        $result = DB::transaction(fn () => app(InvoiceNumberer::class)
            ->allocate($this->company, 'APY'));

        $this->assertInstanceOf(InvoiceAllocation::class, $result);
        $this->assertSame(1, $result->code);
        $this->assertSame('APY1', $result->invcode);
    }

    public function test_allocate_increments_invcount_for_next_caller(): void
    {
        DB::transaction(fn () => app(InvoiceNumberer::class)
            ->allocate($this->company, 'APY'));

        $this->assertSame(2, $this->apyType->fresh()->invcount);

        $next = DB::transaction(fn () => app(InvoiceNumberer::class)
            ->allocate($this->company, 'APY'));

        $this->assertSame(2, $next->code);
        $this->assertSame('APY2', $next->invcode);
        $this->assertSame(3, $this->apyType->fresh()->invcount);
    }

    // NOTE: the "must run inside a transaction" RuntimeException can't be
    // exercised from this test class — RefreshDatabase wraps every test in
    // a parent transaction, so $this->db->transactionLevel() always returns
    // >= 1 here and the check never fires. The assertion is still valid in
    // production (transactionLevel = 0 outside DB::transaction); the test
    // environment lies. We leave the guard in the service code as defense
    // against a future caller dropping the DB::transaction wrapper.

    public function test_allocate_unknown_type_throws(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No invoice_type with code=DOES_NOT_EXIST');

        DB::transaction(fn () => app(InvoiceNumberer::class)
            ->allocate($this->company, 'DOES_NOT_EXIST'));
    }

    public function test_allocate_rejects_movement_only_delivery_type(): void
    {
        // A movement-only 9.x Δελτίο Αποστολής must never be issued through the
        // monetary invoice flow, whichever creator calls allocate() (MYD-003).
        // 9.3 is the FILEABLE delivery type, yet still not a money document —
        // the guard rejects the whole 9.x family, not just the unsupported ones.
        $delivery = InvoiceType::create([
            'company_id' => $this->company->id,
            'code' => 'ΔΑΠ',
            'name' => 'Δελτίο Αποστολής',
            'invcount' => 7,
            'mydata_type' => '9.3',
        ]);

        try {
            DB::transaction(fn () => app(InvoiceNumberer::class)
                ->allocate($this->company, 'ΔΑΠ'));
            $this->fail('Expected a RuntimeException for a movement-only delivery type.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('movement-only', $e->getMessage());
        }

        // Guard throws BEFORE the counter bump → no wasted ΑΑ / no gap.
        $this->assertSame(7, $delivery->fresh()->invcount);

        // The Delivery Notes flow shares this numberer and MUST be able to
        // allocate a 9.x ΑΑ — it opts out of the monetary guard.
        $alloc = DB::transaction(fn () => app(InvoiceNumberer::class)
            ->allocate($this->company, 'ΔΑΠ', allowMovementType: true));
        $this->assertSame(7, $alloc->code);
        $this->assertSame('ΔΑΠ7', $alloc->invcode);
        $this->assertSame(8, $delivery->fresh()->invcount);
    }

    public function test_allocate_is_scoped_per_tenant(): void
    {
        // A second tenant with the same invoice-type code keeps its own counter.
        $other = Company::create([
            'name' => 'Other Co',
            'slug' => 'other-co-'.uniqid(),
            'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata',
            'mydata_mode' => 'off',
        ]);
        InvoiceType::create([
            'company_id' => $other->id,
            'code' => 'APY',
            'name' => 'ΑΠΥ — tenant 2',
            'invcount' => 1,
        ]);

        // Tenant 1 burns three numbers
        for ($i = 0; $i < 3; $i++) {
            DB::transaction(fn () => app(InvoiceNumberer::class)
                ->allocate($this->company, 'APY'));
        }

        // Tenant 2's counter is untouched
        $tenant2First = DB::transaction(fn () => app(InvoiceNumberer::class)
            ->allocate($other, 'APY'));
        $this->assertSame(1, $tenant2First->code);
        $this->assertSame('APY1', $tenant2First->invcode);
    }

    public function test_sequential_allocations_produce_monotonic_numbering(): void
    {
        $allocated = [];
        for ($i = 0; $i < 50; $i++) {
            $allocated[] = DB::transaction(fn () => app(InvoiceNumberer::class)
                ->allocate($this->company, 'APY'))->code;
        }

        $this->assertSame(range(1, 50), $allocated);
        $this->assertSame(51, $this->apyType->fresh()->invcount);
    }

    // For the concurrent hammer test see InvoiceNumbererConcurrencyTest.
    // RefreshDatabase wraps these tests in a transaction so forked children
    // can't see the fixtures; the concurrent test sits in its own file
    // without RefreshDatabase and does its own cleanup.
}
