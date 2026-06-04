<?php

namespace Tests\Feature;

use App\Contracts\EInvoiceSubmitter;
use App\Enums\MyDataMode;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\MyDataMark;
use App\Services\EInvoice\GrProviderSubmitter;
use App\Services\EInvoiceSubmitterFactory;
use App\Services\MyDataSubmitter;
use App\Services\NullSubmitter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Locks in the submitter foundation:
 *   - EInvoiceSubmitterFactory routes tenants to a concrete submitter
 *   - NullSubmitter records SKIPPED audit rows on submit/cancel
 *   - mydata_mode enum accessor round-trips
 *
 * Routing matrix (updated in PR #25 once the real MyDataSubmitter
 * landed):
 *   gr-mydata + sandbox    → MyDataSubmitter
 *   gr-mydata + production → MyDataSubmitter
 *   gr-mydata + off        → NullSubmitter
 *   ee-peppol              → NullSubmitter (until PeppolSubmitter lands)
 *   gr-provider + mode≠off → GrProviderSubmitter (P2; transport from the registry)
 *   gr-provider + mode off → NullSubmitter (staged, not filing)
 *   none                   → NullSubmitter
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

    public function test_factory_returns_mydata_submitter_for_gr_sandbox(): void
    {
        $tenant = $this->makeTenant(MyDataMode::Sandbox);

        $this->assertInstanceOf(MyDataSubmitter::class, app(EInvoiceSubmitterFactory::class)->for($tenant));
    }

    public function test_factory_returns_mydata_submitter_for_gr_production(): void
    {
        $tenant = $this->makeTenant(MyDataMode::Production);

        $this->assertInstanceOf(MyDataSubmitter::class, app(EInvoiceSubmitterFactory::class)->for($tenant));
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

    public function test_factory_returns_gr_provider_submitter_when_mode_active(): void
    {
        // P2: a 'gr-provider' tenant with a non-off mode files via the provider
        // path. The transport is resolved from the registry (Null here, since no
        // real provider is registered) — but the submitter type is the routing claim.
        $tenant = Company::create([
            'name' => 'Provider Active',
            'slug' => 'gr-provider-'.uniqid(),
            'country_code' => 'GR',
            'einvoice_provider' => 'gr-provider',
            'einvoice_provider_key' => 'invosign',
            'einvoice_provider_mode' => 'sandbox',
            'mydata_mode' => 'off',
        ]);

        $this->assertInstanceOf(GrProviderSubmitter::class, app(EInvoiceSubmitterFactory::class)->for($tenant));
    }

    public function test_factory_returns_null_submitter_for_staged_gr_provider(): void
    {
        // mode 'off' = staged, not filing (twin of gr-mydata Off) → NullSubmitter.
        $tenant = Company::create([
            'name' => 'Provider Staged',
            'slug' => 'gr-provider-off-'.uniqid(),
            'country_code' => 'GR',
            'einvoice_provider' => 'gr-provider',
            'einvoice_provider_key' => 'invosign',
            'einvoice_provider_mode' => 'off',
            'mydata_mode' => 'off',
        ]);

        $this->assertInstanceOf(NullSubmitter::class, app(EInvoiceSubmitterFactory::class)->for($tenant));
    }

    public function test_null_submitter_records_skipped_audit_row(): void
    {
        $invoice = $this->makeInvoice();

        $mark = (new NullSubmitter)->submit($invoice);

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

        $mark = (new NullSubmitter)->cancel($invoice, 'Test cancel');

        $this->assertSame('SKIPPED_CANCEL', $mark->mydata_action);
        $this->assertStringContainsString('Test cancel', $mark->request);
    }

    public function test_null_submitter_test_connection_returns_true(): void
    {
        $this->assertTrue((new NullSubmitter)->testConnection());
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
