<?php

namespace Tests\Feature\Delivery;

use App\Models\Company;
use App\Models\InvoiceType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The `delivery:sandbox-validate` orchestrator — the dry-run path must
 * auto-create a test δελτίο, print the 9.3 XML and write a report, with NO
 * network call.
 */
class DeliverySandboxValidateCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_creates_a_test_note_prints_xml_and_writes_report(): void
    {
        $tenant = Company::create([
            'name' => 'Sandbox tenant', 'slug' => 'sbx-'.uniqid(),
            'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata',
            'mydata_mode' => 'sandbox', 'afm' => '801280908',
            'address' => 'Οδός 1', 'city' => 'Αθήνα', 'postcode' => '10431',
        ]);
        InvoiceType::create([
            'company_id' => $tenant->id, 'code' => 'ΔΑΠ', 'name' => 'Δελτίο Αποστολής',
            'invcount' => 1, 'mydata_type' => '9.3',
        ]);

        $report = 'test-sandbox-report.txt';
        $code = Artisan::call('delivery:sandbox-validate', [
            '--tenant' => $tenant->slug,
            '--report' => $report,
        ]);
        $out = Artisan::output();

        $this->assertSame(0, $code);
        $this->assertStringContainsString('<isDeliveryNote>true</isDeliveryNote>', $out);
        $this->assertStringContainsString('category3', $out);
        // A test δελτίο was created + numbered.
        $this->assertDatabaseHas('delivery_notes', ['company_id' => $tenant->id, 'invcode' => 'ΔΑΠ1']);
        // The report file was written.
        $this->assertTrue(Storage::disk('local')->exists($report));
        $this->assertStringContainsString('DRY-RUN', Storage::disk('local')->get($report));
    }

    public function test_missing_tenant_fails_cleanly(): void
    {
        $this->assertSame(1, Artisan::call('delivery:sandbox-validate', ['--tenant' => 'nope-'.uniqid()]));
    }
}
