<?php

namespace Tests\Feature\Invoices;

use App\Models\Attachment;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\Note;
use App\Models\PaymentMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceAttachmentsNotesTest extends TestCase
{
    use RefreshDatabase;

    public function test_invoice_has_attachments_and_internal_notes_independent_of_printed_notes(): void
    {
        $t = Company::create([
            'name' => 'T', 'slug' => 'in-'.uniqid(),
            'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
        $pm = PaymentMethod::create(['company_id' => $t->id, 'description' => 'Cash', 'due_days' => 0, 'is_active' => true]);
        $it = InvoiceType::create(['company_id' => $t->id, 'name' => 'TPY', 'code' => 'TPY', 'invcount' => 0, 'payment_method_id' => $pm->id]);
        $c = Customer::create(['company_id' => $t->id, 'name' => 'K']);

        $inv = Invoice::create([
            'company_id' => $t->id, 'customer_id' => $c->id, 'invoice_type_id' => $it->id,
            'invcode' => 'TPY1', 'code' => 1, 'issued_at' => '2026-03-10',
            'gross_total' => 124, 'net_total' => 100,
            'notes' => 'ΕΥΧΑΡΙΣΤΟΥΜΕ — printed on the PDF',   // the printed field
        ]);

        $inv->internalNotes()->create([
            'company_id' => $t->id, 'body' => 'Εσωτερικό: να ελεγχθεί η διεύθυνση',
        ]);
        Attachment::create([
            'company_id' => $t->id, 'attachable_type' => Invoice::class, 'attachable_id' => $inv->id,
            'disk' => 'local', 'path' => 'attachments/y.pdf', 'original_name' => 'απόδειξη.pdf', 'size' => 10,
        ]);

        // The printed `notes` column is untouched and distinct from internalNotes.
        $this->assertSame('ΕΥΧΑΡΙΣΤΟΥΜΕ — printed on the PDF', $inv->fresh()->notes);
        $this->assertSame(1, $inv->internalNotes()->count());
        $this->assertSame('Εσωτερικό: να ελεγχθεί η διεύθυνση', $inv->internalNotes()->first()->body);
        $this->assertSame(1, $inv->attachments()->count());
        $this->assertSame('απόδειξη.pdf', $inv->attachments()->first()->original_name);
    }
}
