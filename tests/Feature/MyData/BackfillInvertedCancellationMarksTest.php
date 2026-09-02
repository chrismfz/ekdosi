<?php

namespace Tests\Feature\MyData;

use App\Models\Company;
use App\Models\Customer;
use App\Models\DeliveryNote;
use App\Models\Invoice;
use App\Models\InvoiceType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * MYD-023 backfill: the provider cancel paths used to persist
 * `$result->cancellationMark ?? $markToCancel` into `mark`, so a historical CANCEL
 * row holds either the cancellation MARK or the issue MARK with nothing to say
 * which. Under the new column meanings the first kind reads as the exact inverse
 * of the truth.
 *
 * The migration separates them against the document's own INSERT row — the same
 * place `cancel()` reads the MARK to cancel from — and refuses to guess when that
 * row is absent. These tests pin all four cases, because the thing being rewritten
 * is a legal audit trail.
 */
class BackfillInvertedCancellationMarksTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_09_02_000005_backfill_inverted_provider_cancellation_marks.php';

    private Company $tenant;

    private Invoice $invoice;

    private DeliveryNote $note;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Company::create([
            'name' => 'Provider tenant', 'slug' => 'bf-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-provider', 'afm' => '800561849',
        ]);
        $type = InvoiceType::create([
            'company_id' => $this->tenant->id, 'code' => 'TPY', 'name' => 'ΤΠΥ',
            'invcount' => 1, 'mydata_type' => '2.1',
        ]);
        $customer = Customer::create([
            'company_id' => $this->tenant->id, 'name' => 'Πελάτης', 'afm' => '997073525',
        ]);

        $this->invoice = Invoice::create([
            'company_id' => $this->tenant->id, 'invcode' => 'TPY1', 'code' => 1,
            'invoice_type_id' => $type->id, 'customer_id' => $customer->id, 'issued_at' => now(),
            'company_name' => 'Πελάτης', 'vat_no' => '997073525',
        ]);
        $deliveryType = InvoiceType::create([
            'company_id' => $this->tenant->id, 'code' => 'ΔΑ', 'name' => 'Δελτίο Αποστολής',
            'invcount' => 1, 'mydata_type' => '9.3',
        ]);
        $this->note = DeliveryNote::create([
            'company_id' => $this->tenant->id, 'delivery_type_id' => $deliveryType->id,
            'customer_id' => $customer->id, 'invcode' => 'ΔΑ1', 'code' => 1,
            'issued_at' => now(), 'mydata_type' => '9.3', 'move_purpose' => 8,
            'local_status' => 'draft',
        ]);
    }

    /** Re-run the backfill against whatever rows the test has just planted. */
    private function runBackfill(): void
    {
        (require base_path(self::MIGRATION))->up();
    }

    private function mark(array $attributes): int
    {
        return DB::table('mydata_marks')->insertGetId($attributes + [
            'company_id' => $this->tenant->id,
            'invoice_id' => $this->invoice->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_an_inverted_provider_cancel_row_is_un_inverted(): void
    {
        $this->mark(['mark' => '400013829677137', 'mydata_action' => 'PROVIDER_INSERT']);
        $id = $this->mark(['mark' => '400001964598454', 'mydata_action' => 'PROVIDER_CANCEL']);

        $this->runBackfill();

        $row = DB::table('mydata_marks')->find($id);
        $this->assertSame('400013829677137', $row->mark, 'mark must become the cancelled document');
        $this->assertSame('400001964598454', $row->cancellation_mark, 'the cancellation MARK must be preserved, not dropped');
    }

    /**
     * The fallback case: the provider returned no cancellation MARK, so the old
     * code stored the issue MARK. That row already means what it says — and there
     * is genuinely no evidence to invent for it.
     */
    public function test_a_fallback_row_is_left_alone(): void
    {
        $this->mark(['mark' => '400013829677137', 'mydata_action' => 'PROVIDER_INSERT']);
        $id = $this->mark(['mark' => '400013829677137', 'mydata_action' => 'PROVIDER_CANCEL']);

        $this->runBackfill();

        $row = DB::table('mydata_marks')->find($id);
        $this->assertSame('400013829677137', $row->mark);
        $this->assertNull($row->cancellation_mark);
    }

    /** Without a sibling INSERT row the two kinds are indistinguishable — never guess. */
    public function test_a_cancel_row_with_no_sibling_insert_is_untouched(): void
    {
        $id = $this->mark(['mark' => '400001964598454', 'mydata_action' => 'PROVIDER_CANCEL']);

        $this->runBackfill();

        $row = DB::table('mydata_marks')->find($id);
        $this->assertSame('400001964598454', $row->mark);
        $this->assertNull($row->cancellation_mark);
    }

    /** Rows the NEW code wrote are already correct — and re-running must not churn them. */
    public function test_rows_written_by_the_new_code_are_idempotent(): void
    {
        $this->mark(['mark' => '400013829677137', 'mydata_action' => 'PROVIDER_INSERT']);
        $id = $this->mark([
            'mark' => '400013829677137',
            'cancellation_mark' => '400001964598454',
            'mydata_action' => 'PROVIDER_CANCEL',
        ]);

        $this->runBackfill();
        $this->runBackfill();

        $row = DB::table('mydata_marks')->find($id);
        $this->assertSame('400013829677137', $row->mark);
        $this->assertSame('400001964598454', $row->cancellation_mark);
    }

    /** The DIRECT invoice cancel path was always correct — it must not be rewritten. */
    public function test_direct_cancel_rows_are_out_of_scope(): void
    {
        $this->mark(['mark' => '400013829677137', 'mydata_action' => 'INSERT']);
        $id = $this->mark(['mark' => '400013829677137', 'mydata_action' => 'CANCEL']);

        $this->runBackfill();

        $row = DB::table('mydata_marks')->find($id);
        $this->assertSame('400013829677137', $row->mark);
        $this->assertNull($row->cancellation_mark);
    }

    /**
     * Delivery notes: both channels write action CANCEL, and only the provider one
     * could be inverted — so `provider_key` is what separates them. A direct row
     * must survive untouched even when its mark differs from the INSERT row's.
     */
    public function test_delivery_direct_cancel_is_not_rewritten_but_provider_is(): void
    {
        $insert = fn () => DB::table('delivery_marks')->insertGetId([
            'company_id' => $this->tenant->id, 'delivery_note_id' => $this->note->id,
            'mark' => '400013829677137', 'mydata_action' => 'PROVIDER_INSERT',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $insert();

        $direct = DB::table('delivery_marks')->insertGetId([
            'company_id' => $this->tenant->id, 'delivery_note_id' => $this->note->id,
            'mark' => '400009999999999', 'mydata_action' => 'CANCEL', 'provider_key' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $viaProvider = DB::table('delivery_marks')->insertGetId([
            'company_id' => $this->tenant->id, 'delivery_note_id' => $this->note->id,
            'mark' => '400001964598454', 'mydata_action' => 'CANCEL', 'provider_key' => 'fake',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->runBackfill();

        $this->assertSame('400009999999999', DB::table('delivery_marks')->find($direct)->mark);
        $this->assertNull(DB::table('delivery_marks')->find($direct)->cancellation_mark);

        $fixed = DB::table('delivery_marks')->find($viaProvider);
        $this->assertSame('400013829677137', $fixed->mark);
        $this->assertSame('400001964598454', $fixed->cancellation_mark);
    }
}
