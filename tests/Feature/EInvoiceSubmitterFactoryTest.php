<?php

namespace Tests\Feature;

use App\Contracts\EInvoiceSubmitter;
use App\Enums\MyDataMode;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\MyDataMark;
use App\Services\EInvoiceSubmitterFactory;
use App\Services\NullSubmitter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Locks in the submitter foundation:
 *   - EInvoiceSubmitterFactory routes tenants to a concrete submitter
 *   - NullSubmitter records SKIPPED audit rows on submit/cancel
 *   - mydata_mode enum accessor round-trips
 *
 * PR #24 always returns NullSubmitter. PR #25 will add the
 * MyDataSubmitter branch for gr-mydata + mode != off, and this test
 * will gain cases for that path.
 */
class EInvoiceSubmitterFactoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_factory_returns_null_submitter_for_off_mode_greek_tenant(): void
    {
        $tenant = $this->makeTenant(MyDataMode::Off);

        $submitter = app(EInvoiceSubmitterFactory::class)->for($tenant);

        $this->assertInstanceOf(EInvoiceSubmitter::class, $submitter);
        $this->assertInstanceOf(NullSubmitter::class, $submitter);
    }

    public function test_factory_returns_null_submitter_for_sandbox_in_pr24(): void
    {
        // In PR #24, the real MyDataSubmitter doesn't exist yet —
        // factory falls through to NullSubmitter for every tenant.
        // PR #25 will extend this so sandbox + production return the
        // real submitter; that test is added then.
        $tenant = $this->makeTenant(MyDataMode::Sandbox);

        $this->assertInstanceOf(NullSubmitter::class, app(EInvoiceSubmitterFactory::class)->for($tenant));
    }

    public function test_factory_returns_null_submitter_for_none_provider(): void
    {
        $tenant = Company::create([
            'name' => 'PDF Only',
            'slug' => 'pdf-only-'.uniqid(),
            'country_code' => 'EE',
            'einvoice_provider' => 'none',
            'mydata_mode' => 'off',
        ]);

        $this->assertInstanceOf(NullSubmitter::class, app(EInvoiceSubmitterFactory::class)->for($tenant));
    }

    public function test_null_submitter_records_skipped_audit_row(): void
    {
        $invoice = $this->makeInvoice();

        $mark = (new NullSubmitter())->submit($invoice);

        $this->assertInstanceOf(MyDataMark::class, $mark);
        $this->assertSame('SKIPPED', $mark->mydata_action);
        $this->assertSame($invoice->id, $mark->invoice_id);
        // Invoice's mirror columns remain untouched — NullSubmitter
        // doesn't pretend an AADE filing happened.
        $this->assertNull($invoice->fresh()->mydata_state);
        $this->assertNull($invoice->fresh()->mydata_mark);
    }

    public function test_null_submitter_records_skipped_cancel(): void
    {
        $invoice = $this->makeInvoice();

        $mark = (new NullSubmitter())->cancel($invoice, 'Test cancel');

        $this->assertSame('SKIPPED_CANCEL', $mark->mydata_action);
        $this->assertStringContainsString('Test cancel', $mark->request);
    }

    public function test_null_submitter_test_connection_returns_true(): void
    {
        $this->assertTrue((new NullSubmitter())->testConnection());
    }

    public function test_mydata_mode_enum_accessor_returns_typed_value(): void
    {
        $tenant = $this->makeTenant(MyDataMode::Sandbox);

        $this->assertSame(MyDataMode::Sandbox, $tenant->mydata_mode_enum);
        $this->assertSame('sandbox', $tenant->mydata_mode);
    }

    public function test_mydata_mode_enum_falls_back_to_off_on_unknown_value(): void
    {
        $tenant = Company::create([
            'name' => 'Probe',
            'slug' => 'probe-'.uniqid(),
            'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata',
            'mydata_mode' => 'sandbox',
        ]);
        // Force a value that isn't in the enum (e.g. legacy data, future
        // value rolled back). Accessor must fall back gracefully rather
        // than throwing.
        \DB::table('companies')->where('id', $tenant->id)->update(['mydata_mode' => 'experimental']);

        $this->assertSame(MyDataMode::Off, $tenant->fresh()->mydata_mode_enum);
    }

    private function makeTenant(MyDataMode $mode): Company
    {
        return Company::create([
            'name' => 'Tenant '.$mode->value,
            'slug' => 'submitter-'.$mode->value.'-'.uniqid(),
            'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata',
            'mydata_mode' => $mode->value,
        ]);
    }

    private function makeInvoice(): Invoice
    {
        $tenant = $this->makeTenant(MyDataMode::Off);
        $type = InvoiceType::create([
            'company_id' => $tenant->id,
            'code' => 'APY',
            'name' => 'Test',
            'invcount' => 1,
        ]);
        $customer = Customer::create([
            'company_id' => $tenant->id,
            'name' => 'Test customer',
        ]);
        return Invoice::create([
            'company_id' => $tenant->id,
            'invcode' => 'APY1',
            'code' => 1,
            'invoice_type_id' => $type->id,
            'customer_id' => $customer->id,
            'issued_at' => now(),
        ]);
    }
}
