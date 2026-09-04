<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CMR — international road consignment note (CMR Convention). A TRANSPORT
 * document, NOT a myDATA παραστατικό: no AADE submission, no ΑΑ counter, no VAT.
 *
 * First-class + self-contained (see docs/archive/cmr-international-delivery.md): it can
 * stand alone (third-party goods passing through us) OR reference one of our
 * documents via the OPTIONAL polymorphic `source` (DeliveryNote | Invoice).
 * When sourced, it's pre-filled (Greek→Latin transliteration) into an editable
 * DRAFT the operator corrects to English before printing. English because a
 * GR→BG (etc.) consignment note must be in Latin script.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cmr_notes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();

            $t->unsignedInteger('number');                 // per-company counter (archival, not fiscal)
            $t->string('reference_no', 40)->nullable();    // box top-right
            $t->string('status', 20)->default('draft');    // draft / finalized (soft; not myDATA)

            // Optional polymorphic source: DeliveryNote | Invoice | null (standalone)
            $t->nullableMorphs('source');
            $t->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();

            $t->dateTime('issued_at');

            // Boxes 1–4 — free Latin text (pre-filled by transliteration, editable)
            $t->text('sender_text')->nullable();           // box 1
            $t->text('consignee_text')->nullable();        // box 2
            $t->text('delivery_text')->nullable();         // box 3 place of delivery
            $t->string('taking_over_place')->nullable();   // box 4 place
            $t->dateTime('taking_over_at')->nullable();    // box 4 date

            // Carrier — boxes 16/17/18 + plates under 23
            $t->string('carrier_name')->nullable();        // box 16
            $t->string('carrier_address')->nullable();
            $t->string('successive_carrier')->nullable();  // box 17
            $t->text('carrier_reservations')->nullable();  // box 18
            $t->string('tractor_plate', 40)->nullable();
            $t->string('trailer_plate', 40)->nullable();

            // Documents / instructions / agreements — boxes 5/13/19
            $t->text('annexed_documents')->nullable();     // box 5
            $t->text('sender_instructions')->nullable();   // box 13
            $t->text('special_agreements')->nullable();    // box 19

            // Freight charges — boxes 14/15/20
            $t->boolean('freight_paid')->nullable();                     // box 14 (paid / to be paid)
            $t->string('charges_to_be_paid_by', 12)->nullable();         // box 20: sender | consignee
            $t->decimal('carriage_charges', 14, 2)->nullable();
            $t->decimal('reductions', 14, 2)->nullable();
            $t->decimal('balance', 14, 2)->nullable();
            $t->decimal('supplement', 14, 2)->nullable();
            $t->decimal('misc_charges', 14, 2)->nullable();
            $t->decimal('total_charges', 14, 2)->nullable();
            $t->decimal('cash_on_delivery', 14, 2)->nullable();          // box 15

            // Box 21 + copies + print state
            $t->string('established_place')->nullable();
            $t->date('established_on')->nullable();
            $t->unsignedTinyInteger('copies_count')->default(4);
            $t->boolean('printed')->default(false);
            $t->text('notes')->nullable();

            $t->timestamps();
            $t->softDeletes();

            $t->unique(['company_id', 'number']);
            $t->index(['company_id', 'issued_at']);
            // (source_type, source_id) index already created by nullableMorphs().
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cmr_notes');
    }
};
