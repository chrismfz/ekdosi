<?php

namespace Tests\Feature\WhmcsInbox;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\PendingWhmcsInvoice;
use App\Services\Whmcs\WhmcsBridgeClient;
use App\Services\Whmcs\WhmcsBridgeClientFactory;
use App\Services\Whmcs\WhmcsWritebackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * 1→N write-back: a CONSOLIDATED παραστατικό covers N WHMCS child invoices, so
 * its MARK must be pushed back to EVERY child id (not just the mass-pay container)
 * — otherwise the rolled-up WHMCS invoices show no MARK and a later fetch re-stages
 * them. MassPayConsolidator records the child ids in payload.ekdosi_consolidated_children.
 */
class MassPayWritebackTest extends TestCase
{
    use RefreshDatabase;

    private function scaffold(array $payload): array
    {
        $tenant = Company::create([
            'name' => 'WB OE', 'slug' => 'wb-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'sandbox',
        ]);
        $customer = Customer::create(['company_id' => $tenant->id, 'name' => 'C', 'afm' => '997890734']);
        $type = InvoiceType::create(['company_id' => $tenant->id, 'code' => 'TPY', 'name' => 'ΤΠΥ', 'invcount' => 1]);
        $invoice = Invoice::create([
            'company_id' => $tenant->id, 'invoice_type_id' => $type->id, 'customer_id' => $customer->id,
            'code' => 6980, 'invcode' => 'TPY6980', 'issued_at' => now(), 'local_status' => 'active',
            'mydata_mark' => '400001234567890',
        ]);
        $pending = PendingWhmcsInvoice::create([
            'company_id' => $tenant->id, 'whmcs_invoice_id' => 32310, 'status' => PendingWhmcsInvoice::STATUS_DRAFTED,
            'match_reason' => PendingWhmcsInvoice::REASON_AFM, 'invoice_id' => $invoice->id, 'payload' => $payload,
        ]);

        return [$tenant, $invoice, $pending];
    }

    public function test_pushmark_fans_out_the_mark_to_every_consolidated_child(): void
    {
        [$tenant, $invoice, $pending] = $this->scaffold([
            'invoiceid' => 32310, 'total' => '647.70',
            'ekdosi_consolidated_children' => [32280, 32263, 32256],
        ]);

        $calls = [];
        $client = Mockery::mock(WhmcsBridgeClient::class);
        $client->shouldReceive('setInvoiced')->andReturnUsing(function (int $id) use (&$calls) {
            $calls[] = $id;
        });
        $factory = Mockery::mock(WhmcsBridgeClientFactory::class);
        $factory->shouldReceive('for')->andReturn($client);
        $this->app->instance(WhmcsBridgeClientFactory::class, $factory);

        app(WhmcsWritebackService::class)->pushMark($tenant, $pending, $invoice, '400001234567890');

        sort($calls);
        $this->assertSame([32256, 32263, 32280, 32310], $calls, 'the mass-pay + all 3 children get the MARK');
        $this->assertSame(PendingWhmcsInvoice::WRITEBACK_SUCCEEDED, $pending->fresh()->whmcs_writeback_state);
    }

    public function test_pushmark_pushes_only_the_row_for_an_ordinary_single_invoice(): void
    {
        [$tenant, $invoice, $pending] = $this->scaffold(['invoiceid' => 32309, 'total' => '81.84']);

        $calls = [];
        $client = Mockery::mock(WhmcsBridgeClient::class);
        $client->shouldReceive('setInvoiced')->andReturnUsing(function (int $id) use (&$calls) {
            $calls[] = $id;
        });
        $factory = Mockery::mock(WhmcsBridgeClientFactory::class);
        $factory->shouldReceive('for')->andReturn($client);
        $this->app->instance(WhmcsBridgeClientFactory::class, $factory);

        app(WhmcsWritebackService::class)->pushMark($tenant, $pending, $invoice, '400001234567890');

        $this->assertSame([32310], $calls, 'no fan-out for a plain invoice (just the row itself)');
    }
}
