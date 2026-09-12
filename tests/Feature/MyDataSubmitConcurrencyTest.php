<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\InvoiceType;
use App\Models\VatCategory;
use App\Services\MyDataSubmitter;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * MYD-2 (AUDIT): submit() must not let two concurrent attempts on the SAME
 * invoice both POST to AADE (→ two MARKs = doubly-declared income). It takes a
 * cache atomic lock and re-reads state fresh under it, so a second attempt is
 * refused while one is in flight, and a sequential retry sees the committed
 * VALID state.
 */
class MyDataSubmitConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Company::create([
            'name' => 'Conc', 'slug' => 'conc-'.uniqid(),
            'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'sandbox',
            'afm' => '800561849', 'mydata_aade_id_sandbox' => 'U', 'mydata_subscription_key_sandbox' => 'K',
        ]);
        $type = InvoiceType::create([
            'company_id' => $this->tenant->id, 'code' => 'TPY', 'name' => 'ΤΠΥ',
            'invcount' => 1, 'mydata_type' => '1.1',
        ]);
        $customer = Customer::create(['company_id' => $this->tenant->id, 'name' => 'C', 'afm' => '123456789']);
        VatCategory::create(['company_id' => $this->tenant->id, 'description' => '24%', 'rate' => 24, 'is_default' => true]);

        $this->invoice = Invoice::create([
            'company_id' => $this->tenant->id, 'invcode' => 'TPY1', 'code' => 1,
            'invoice_type_id' => $type->id, 'customer_id' => $customer->id,
            'issued_at' => now(), 'header_discount_percent' => 0,
        ]);
        InvoiceLine::create([
            'company_id' => $this->tenant->id, 'invoice_id' => $this->invoice->id,
            'qty' => 1, 'vat_percent' => 24, 'net_price' => 100, 'gross_price' => 124,
        ]);
    }

    private function successMock(): MockHandler
    {
        $xml = file_get_contents(base_path('tests/Fixtures/firebed/send-invoices-single-response.xml'));

        return new MockHandler([new GuzzleResponse(200, [], $xml)]);
    }

    public function test_refuses_when_a_submission_is_already_in_flight(): void
    {
        // Simulate the other worker holding the per-invoice lock.
        $held = Cache::lock('mydata-submit:'.$this->invoice->id, 120);
        $this->assertTrue($held->get());

        try {
            (new MyDataSubmitter($this->tenant))->submit($this->invoice->fresh('lines'));
            $this->fail('Expected the second concurrent submit to be refused.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('ήδη σε εξέλιξη', $e->getMessage());
        } finally {
            $held->release();
        }

        // Nothing was filed while the lock was held.
        $this->assertNull($this->invoice->fresh()->mydata_state);
    }

    public function test_lock_is_released_after_a_successful_submit(): void
    {
        $mark = (new MyDataSubmitter($this->tenant, $this->successMock()))->submit($this->invoice->fresh('lines'));
        $this->assertSame('VALID', $this->invoice->fresh()->mydata_state);
        $this->assertNotNull($mark->mark);

        // The per-invoice lock is free again (the finally released it).
        $lock = Cache::lock('mydata-submit:'.$this->invoice->id, 120);
        $this->assertTrue($lock->get(), 'the submit lock must be released after completion');
        $lock->release();
    }

    public function test_stale_in_memory_state_is_refreshed_under_the_lock(): void
    {
        // Another worker already filed it: the DB row is VALID, but our
        // in-memory copy still reads null (loaded before that commit). The
        // fresh re-read under the lock must catch it and refuse a second POST.
        $stale = $this->invoice->fresh('lines');   // mydata_state null in memory
        Invoice::whereKey($this->invoice->id)->firstOrFail()
            ->forceFill(['mydata_state' => 'VALID', 'mydata_mark' => '400000000000001'])->save();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/already filed at myDATA/');

        (new MyDataSubmitter($this->tenant, $this->successMock()))->submit($stale);
    }
}
