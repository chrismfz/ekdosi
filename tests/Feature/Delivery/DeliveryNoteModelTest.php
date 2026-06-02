<?php

namespace Tests\Feature\Delivery;

use App\Models\Company;
use App\Models\Customer;
use App\Models\DeliveryMark;
use App\Models\DeliveryNote;
use App\Models\DeliveryNoteLine;
use App\Models\InvoiceType;
use App\Services\InvoiceNumberer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * delivery_notes / delivery_note_lines / delivery_marks schema + relations, and
 * that the existing InvoiceNumberer allocates a delivery series unchanged (the
 * delivery type is just an invoice_types row, e.g. 9.3).
 */
class DeliveryNoteModelTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private InvoiceType $type;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Company::create([
            'name' => 'Delivery test',
            'slug' => 'dn-'.uniqid(),
            'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata',
            'mydata_mode' => 'sandbox',
            'afm' => '801280908',
        ]);

        $this->type = InvoiceType::create([
            'company_id' => $this->tenant->id,
            'code' => 'DA',
            'name' => 'Δελτίο Αποστολής',
            'invcount' => 1,
            'mydata_type' => '9.3',
        ]);
    }

    public function test_numberer_allocates_a_delivery_series(): void
    {
        $allocation = DB::transaction(
            fn () => app(InvoiceNumberer::class)->allocate($this->tenant, 'DA'),
        );

        $this->assertSame(1, $allocation->code);
        $this->assertSame('DA', $allocation->series);
        $this->assertSame('DA1', $allocation->invcode);
        $this->assertSame(2, $this->type->fresh()->invcount, 'counter bumped');
    }

    public function test_create_delivery_note_with_lines_and_mark(): void
    {
        $customer = Customer::create([
            'company_id' => $this->tenant->id,
            'name' => 'Παραλήπτης ΑΕ',
            'afm' => '123456789',
        ]);

        $note = DeliveryNote::create([
            'company_id' => $this->tenant->id,
            'delivery_type_id' => $this->type->id,
            'customer_id' => $customer->id,
            'invcode' => 'DA1',
            'code' => 1,
            'issued_at' => now(),
            'mydata_type' => '9.3',
            'move_purpose' => 8,                 // Ενδοδιακίνηση
            'local_status' => 'draft',
            'vehicle_number' => 'ΑΒΧ1234',
            'recipient_name' => 'Παραλήπτης ΑΕ',
        ]);

        $note->lines()->create([
            'company_id' => $this->tenant->id,
            'product_descr' => 'Server Dell R740',
            'qty' => 2,
        ]);

        $note->marks()->create([
            'company_id' => $this->tenant->id,
            'mark' => '4000001',
            'mydata_action' => 'INSERT',
        ]);

        $note->refresh();

        $this->assertCount(1, $note->lines);
        $this->assertSame('Server Dell R740', $note->lines->first()->product_descr);
        $this->assertSame('9.3', $note->deliveryType->mydata_type);
        $this->assertSame('Παραλήπτης ΑΕ', $note->customer->name);
        $this->assertSame('4000001', $note->latestMark->mark);
        $this->assertSame(8, $note->move_purpose);
    }

    public function test_mydata_cache_and_lifecycle_marks_are_guarded(): void
    {
        $note = DeliveryNote::create([
            'company_id' => $this->tenant->id,
            'delivery_type_id' => $this->type->id,
            'invcode' => 'DA2',
            'code' => 2,
            'issued_at' => now(),
            // These must be ignored by mass-assignment (written only via forceFill).
            'mydata_state' => 'VALID',
            'mydata_mark' => 'HACK',
            'delivery_state' => 'in_transit',
            'transfer_mark' => 'HACK2',
        ]);

        $this->assertNull($note->mydata_state);
        $this->assertNull($note->mydata_mark);
        $this->assertNull($note->delivery_state);
        $this->assertNull($note->transfer_mark);

        // forceFill is the sanctioned writer.
        $note->forceFill(['mydata_state' => 'VALID', 'mydata_mark' => '9001', 'delivery_state' => 'registered'])->save();
        $this->assertSame('VALID', $note->fresh()->mydata_state);
        $this->assertSame('registered', $note->fresh()->delivery_state);
    }
}
