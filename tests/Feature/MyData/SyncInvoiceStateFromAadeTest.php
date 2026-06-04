<?php

namespace Tests\Feature\MyData;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Services\MyData\SyncInvoiceStateFromAade;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * The explicit, operator-confirmed «Συγχρονισμός κατάστασης από ΑΑΔΕ» —
 * applies AADE's live state to the local invoice, 2-way.
 */
class SyncInvoiceStateFromAadeTest extends TestCase
{
    use RefreshDatabase;

    private function invoice(string $mydataState, string $localStatus): Invoice
    {
        $tenant = Company::create([
            'name' => 'ΑΚΜΗ ΟΕ', 'slug' => 'sync-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'afm' => '800561849',
        ]);
        $type = InvoiceType::create([
            'company_id' => $tenant->id, 'code' => 'TPY', 'name' => 'ΤΠΥ', 'invcount' => 1, 'mydata_type' => '2.1',
        ]);
        $customer = Customer::create(['company_id' => $tenant->id, 'name' => 'Πελάτης', 'afm' => '997073525']);

        $invoice = Invoice::create([
            'company_id' => $tenant->id, 'invcode' => 'TPY1', 'code' => 1,
            'invoice_type_id' => $type->id, 'customer_id' => $customer->id, 'issued_at' => now(),
            'company_name' => 'Πελάτης', 'vat_no' => '997073525',
        ]);
        $invoice->forceFill([
            'mydata_state' => $mydataState,
            'local_status' => $localStatus,
            'mydata_mark' => '400013829677137',
        ])->save();

        return $invoice->fresh();
    }

    public function test_cancelled_syncs_local_to_cancelled(): void
    {
        $invoice = $this->invoice('VALID', 'active');

        $result = app(SyncInvoiceStateFromAade::class)->sync($invoice, 'CANCELLED');

        $this->assertTrue($result['changed']);
        $fresh = $invoice->fresh();
        $this->assertSame('CANCELLED', $fresh->mydata_state);
        $this->assertSame('cancelled', $fresh->local_status);
        $this->assertDatabaseHas('mydata_marks', [
            'invoice_id' => $invoice->id,
            'mydata_action' => 'STATE_SYNC',
        ]);
    }

    public function test_valid_uncancels_a_wrongly_cancelled_invoice(): void
    {
        // The 2026-06-05 case: local cancelled (from a 301-rejected cancel)
        // while AADE actually has it VALID. Sync must un-cancel locally.
        $invoice = $this->invoice('CANCELLED', 'cancelled');

        $result = app(SyncInvoiceStateFromAade::class)->sync($invoice, 'VALID');

        $this->assertTrue($result['changed']);
        $fresh = $invoice->fresh();
        $this->assertSame('VALID', $fresh->mydata_state);
        $this->assertSame('active', $fresh->local_status);
    }

    public function test_valid_keeps_business_local_status_when_not_cancelled(): void
    {
        // mydata_state diverges (CANCELLED→VALID) but local_status is a business
        // intent that isn't "cancelled" → leave it untouched.
        $invoice = $this->invoice('CANCELLED', 'active');

        $result = app(SyncInvoiceStateFromAade::class)->sync($invoice, 'VALID');

        $this->assertTrue($result['changed']);
        $this->assertSame('VALID', $invoice->fresh()->mydata_state);
        $this->assertSame('active', $invoice->fresh()->local_status);
    }

    public function test_no_op_when_already_in_sync(): void
    {
        $invoice = $this->invoice('VALID', 'active');

        $result = app(SyncInvoiceStateFromAade::class)->sync($invoice, 'VALID');

        $this->assertFalse($result['changed']);
        $this->assertDatabaseMissing('mydata_marks', [
            'invoice_id' => $invoice->id,
            'mydata_action' => 'STATE_SYNC',
        ]);
    }

    public function test_throws_on_unknown_state(): void
    {
        $invoice = $this->invoice('VALID', 'active');

        $this->expectException(RuntimeException::class);
        app(SyncInvoiceStateFromAade::class)->sync($invoice, 'PENDING');
    }
}
