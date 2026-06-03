<?php

namespace Tests\Feature\Delivery;

use App\Models\Company;
use App\Models\DeliveryNote;
use App\Models\DeliveryNoteLine;
use App\Models\InvoiceType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * The `delivery:test-submit` CLI — the dry-run path must print the 9.3 XML with
 * no network call (used to sandbox-validate the payload before go-live).
 */
class DeliveryTestSubmitCommandTest extends TestCase
{
    use RefreshDatabase;

    private function makeNote(): DeliveryNote
    {
        $tenant = Company::create([
            'name' => 'DA cmd', 'slug' => 'dacmd-'.uniqid(),
            'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata',
            'mydata_mode' => 'sandbox', 'afm' => '801280908',
        ]);
        $type = InvoiceType::create([
            'company_id' => $tenant->id, 'code' => 'ΔΑΠ', 'name' => 'Δελτίο Αποστολής',
            'invcount' => 1, 'mydata_type' => '9.3',
        ]);
        $note = DeliveryNote::create([
            'company_id' => $tenant->id, 'delivery_type_id' => $type->id,
            'invcode' => 'ΔΑΠ1', 'code' => 1, 'issued_at' => now(), 'mydata_type' => '9.3',
            'move_purpose' => 8, 'local_status' => 'draft',
            'vehicle_number' => 'ΙΑΒ1234', 'transport_type' => 6,
            'loading_street' => 'Φόρτωσης', 'loading_postcode' => '11111', 'loading_city' => 'Αθήνα',
            'delivery_street' => 'Παράδοσης', 'delivery_postcode' => '22222', 'delivery_city' => 'Θεσσαλονίκη',
        ]);
        DeliveryNoteLine::create([
            'company_id' => $tenant->id, 'delivery_note_id' => $note->id,
            'qty' => 3, 'measurement_unit' => 1, 'product_descr' => 'Κιβώτια',
        ]);

        return $note->fresh('lines');
    }

    public function test_dry_run_prints_the_delivery_xml(): void
    {
        $note = $this->makeNote();

        $code = Artisan::call('delivery:test-submit', ['note' => $note->id]);
        $out = Artisan::output();

        $this->assertSame(0, $code);
        $this->assertStringContainsString('<invoiceType>9.3</invoiceType>', $out);
        $this->assertStringContainsString('<isDeliveryNote>true</isDeliveryNote>', $out);
        $this->assertStringContainsString('category3', $out); // «Διακίνηση» characterization
    }

    public function test_unknown_note_fails_cleanly(): void
    {
        $this->artisan('delivery:test-submit', ['note' => 999999])
            ->assertExitCode(1);
    }
}
