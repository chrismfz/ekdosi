<?php

namespace Tests\Feature\WhmcsInbox;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\PaymentMethod;
use App\Models\PendingWhmcsInvoice;
use App\Models\VatCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * WH-7: the whmcs:retry-writebacks batch sweep — re-pushes failed MARK
 * write-backs (AADE untouched). Reuses WhmcsWritebackService::retryWriteback.
 */
class WhmcsRetryWritebacksCommandTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private InvoiceType $type;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Company::create([
            'name' => 'T', 'slug' => 'rwb-'.uniqid(),
            'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'sandbox',
            // hasWhmcsIntegration() requires all three + the bridge secret for pushMark.
            'whmcs_api_url' => 'https://whmcs.example.com/includes/api.php',
            'whmcs_api_identifier' => 'id', 'whmcs_api_secret' => 'secret',
            'whmcs_webhook_secret' => str_repeat('a', 64),
        ]);
        VatCategory::create(['company_id' => $this->tenant->id, 'name' => '24%', 'rate' => 24.00, 'is_default' => true]);
        $pm = PaymentMethod::create(['company_id' => $this->tenant->id, 'name' => 'Cash', 'due_days' => 0, 'is_active' => true]);
        $this->type = InvoiceType::create([
            'company_id' => $this->tenant->id, 'name' => 'ΤΠΥ', 'code' => 'ΤΠΥ', 'invcount' => 5, 'payment_method_id' => $pm->id,
        ]);
        $this->customer = Customer::create(['company_id' => $this->tenant->id, 'name' => 'ΑΚΜΕ', 'afm' => '111111111']);
    }

    private function failedRow(int $whmcsId, string $mark): PendingWhmcsInvoice
    {
        $invoice = Invoice::create([
            'company_id' => $this->tenant->id, 'invoice_type_id' => $this->type->id,
            'customer_id' => $this->customer->id, 'code' => $whmcsId, 'invcode' => 'ΤΠΥ'.$whmcsId,
            'issued_at' => now(), 'local_status' => 'active', 'net_total' => 100, 'gross_total' => 124,
        ]);
        $invoice->forceFill(['mydata_state' => 'VALID', 'mydata_mark' => $mark])->save();

        return PendingWhmcsInvoice::create([
            'company_id' => $this->tenant->id, 'whmcs_invoice_id' => $whmcsId,
            'payload' => ['invoiceid' => $whmcsId, 'userid' => 1, 'date' => '2026-06-01', 'total' => '124.00',
                'items' => ['item' => [['description' => 'X', 'amount' => '124.00', 'taxed' => '1']]]],
            'match_reason' => PendingWhmcsInvoice::REASON_LINKED,
            'status' => PendingWhmcsInvoice::STATUS_FILED,
            'customer_id' => $this->customer->id,
            'invoice_id' => $invoice->id,
            'mydata_mark' => $mark,
            'whmcs_writeback_state' => PendingWhmcsInvoice::WRITEBACK_FAILED,
            'whmcs_writeback_error' => 'db down',
        ]);
    }

    public function test_it_retries_failed_rows_and_flips_them_to_succeeded(): void
    {
        Http::fake([
            'https://whmcs.example.com/modules/addons/ekdosi_bridge/inbound.php' => Http::response(['status' => 'ok'], 200),
        ]);

        $row = $this->failedRow(4001, '400001111');

        $this->artisan('whmcs:retry-writebacks', ['--tenant' => $this->tenant->slug])
            ->assertExitCode(0);

        $this->assertSame(PendingWhmcsInvoice::WRITEBACK_SUCCEEDED, $row->fresh()->whmcs_writeback_state);
        $this->assertNull($row->fresh()->whmcs_writeback_error);
    }

    public function test_dry_run_pushes_nothing(): void
    {
        Http::preventStrayRequests();

        $row = $this->failedRow(4002, '400002222');

        $this->artisan('whmcs:retry-writebacks', ['--tenant' => $this->tenant->slug, '--dry-run' => true])
            ->assertExitCode(0);

        // Still failed — no bridge call made (preventStrayRequests would throw).
        $this->assertSame(PendingWhmcsInvoice::WRITEBACK_FAILED, $row->fresh()->whmcs_writeback_state);
    }

    public function test_unknown_tenant_is_invalid(): void
    {
        $this->artisan('whmcs:retry-writebacks', ['--tenant' => 'nope-'.uniqid()])
            ->assertExitCode(2);
    }
}
