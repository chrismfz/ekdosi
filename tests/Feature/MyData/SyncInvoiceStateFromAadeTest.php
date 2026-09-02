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

    /**
     * MYD-023: adopting a cancellation must record WHICH cancellation caused it.
     * Without the mark on the STATE_SYNC row the database holds a terminal
     * cancelled state it cannot account for.
     */
    public function test_cancellation_mark_is_recorded_on_the_state_sync_row(): void
    {
        $invoice = $this->invoice('VALID', 'active');

        app(SyncInvoiceStateFromAade::class)->sync($invoice, 'CANCELLED', '400001964598454');

        $this->assertDatabaseHas('mydata_marks', [
            'invoice_id' => $invoice->id,
            'mydata_action' => 'STATE_SYNC',
            'mark' => '400013829677137',          // the invoice's own MARK
            'cancellation_mark' => '400001964598454', // AADE's cancellation MARK
        ]);
    }

    /**
     * The mark is evidence, not a precondition. Unlike the expense twin this must
     * NOT refuse: it is the route that repairs an invoice whose cancellation we
     * learned about late, and refusing would strand the very document it exists
     * to fix.
     */
    public function test_cancellation_without_a_mark_still_syncs(): void
    {
        $invoice = $this->invoice('VALID', 'active');

        $result = app(SyncInvoiceStateFromAade::class)->sync($invoice, 'CANCELLED', '   ');

        $this->assertTrue($result['changed']);
        $this->assertSame('CANCELLED', $invoice->fresh()->mydata_state);
        $this->assertDatabaseHas('mydata_marks', [
            'invoice_id' => $invoice->id,
            'mydata_action' => 'STATE_SYNC',
            'cancellation_mark' => null,
        ]);
    }

    /**
     * The only caller passes this from a public Livewire property, which is
     * client-writable. An arbitrary string must never become «AADE's cancellation
     * MARK» in the legal audit trail, and an over-long one must not abort the
     * sync on a column-length error either.
     */
    public function test_a_value_that_is_not_a_mark_is_recorded_as_no_evidence(): void
    {
        foreach (['<script>x</script>', '400001964598454; DROP', '40000196459845X', str_repeat('9', 41)] as $junk) {
            $invoice = $this->invoice('VALID', 'active');

            $result = app(SyncInvoiceStateFromAade::class)->sync($invoice, 'CANCELLED', $junk);

            $this->assertTrue($result['changed'], "sync must still succeed for: {$junk}");
            $this->assertSame('CANCELLED', $invoice->fresh()->mydata_state);
            $this->assertDatabaseHas('mydata_marks', [
                'invoice_id' => $invoice->id,
                'mydata_action' => 'STATE_SYNC',
                'cancellation_mark' => null,
            ]);
        }
    }

    /** A cancellation mark is meaningless on a VALID sync — never carried over. */
    public function test_valid_sync_does_not_store_a_cancellation_mark(): void
    {
        $invoice = $this->invoice('CANCELLED', 'cancelled');

        app(SyncInvoiceStateFromAade::class)->sync($invoice, 'VALID', '400001964598454');

        $this->assertDatabaseHas('mydata_marks', [
            'invoice_id' => $invoice->id,
            'mydata_action' => 'STATE_SYNC',
            'cancellation_mark' => null,
        ]);
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

    public function test_valid_uncancels_even_when_mydata_state_already_valid(): void
    {
        // Odd combo: mydata_state already VALID but local_status wrongly cancelled
        // → must still fix local_status (not early-return as a no-op on state).
        $invoice = $this->invoice('VALID', 'cancelled');

        $result = app(SyncInvoiceStateFromAade::class)->sync($invoice, 'VALID');

        $this->assertTrue($result['changed']);
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
