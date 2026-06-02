<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Παραστατικά Διακίνησης (Δελτίο Αποστολής / e-transport). A value-LESS twin of
 * `invoices`: the document is issued via the SAME SendInvoices path with a 9.x
 * type, but it carries no money/VAT (so it stays out of InvoiceScope and the
 * money services). Kept in its own table — like quotes — to never pollute the
 * money/reporting queries. The delivery-lifecycle layer (RegisterTransfer →
 * ConfirmDeliveryOutcome → …) writes the `*_mark`/`delivery_state` columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_notes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->unsignedInteger('legacy_id')->nullable();

            $t->string('invcode', 15);                 // series prefix + ΑΑ
            $t->unsignedBigInteger('code');            // ΑΑ within the type
            $t->foreignId('delivery_type_id')->constrained('invoice_types')->restrictOnDelete();
            $t->foreignId('customer_id')->nullable()->constrained()->nullOnDelete(); // recipient (null = ενδοδιακίνηση)
            $t->dateTime('issued_at');
            $t->string('mydata_type', 10)->nullable(); // snapshot, e.g. 9.3 (frozen at submit)

            // Movement (§8.14 + delivery header)
            $t->unsignedTinyInteger('move_purpose')->nullable();      // §8.14 σκοπός διακίνησης
            $t->string('other_move_purpose_title', 120)->nullable();  // when move_purpose=19
            $t->foreignId('distribution_aim_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('delivery_method_id')->nullable()->constrained()->nullOnDelete();

            // Transport
            $t->dateTime('dispatch_at')->nullable();
            $t->string('vehicle_number', 40)->nullable();
            $t->unsignedTinyInteger('transport_type')->nullable();    // DGM TransportType
            $t->string('carrier_afm', 20)->nullable();

            // Addresses (loading = issuer point, delivery = recipient point) — both
            // mandatory for 9.x / isDeliveryNote per the spec; snapshot at issue.
            $t->string('loading_address', 120)->nullable();
            $t->string('loading_postcode', 10)->nullable();
            $t->string('loading_city', 60)->nullable();
            $t->string('delivery_address', 120)->nullable();
            $t->string('delivery_postcode', 10)->nullable();
            $t->string('delivery_city', 60)->nullable();
            $t->string('recipient_name', 120)->nullable();
            $t->string('recipient_afm', 20)->nullable();

            $t->string('local_status', 20)->default('draft'); // draft / active / cancelled
            $t->boolean('printed')->default(false);
            $t->text('notes')->nullable();

            // myDATA cache (source of truth = delivery_marks) — written ONLY by the
            // submitter via forceFill, mirroring invoices.
            $t->boolean('mydata_sent')->nullable();
            $t->string('mydata_state', 30)->nullable();   // VALID / CANCELLED (issue MARK)
            $t->string('mydata_mark', 120)->nullable();
            $t->string('mydata_url', 1500)->nullable();   // qrUrl — the lifecycle key

            // e-transport lifecycle (written by the lifecycle service via forceFill)
            $t->string('delivery_state', 30)->nullable(); // §8.22: registered/in_transit/delivered/failed/rejected/cancelled
            $t->string('transfer_mark', 50)->nullable();  // RegisterTransfer
            $t->string('outcome_mark', 50)->nullable();   // ConfirmDeliveryOutcome
            $t->string('reject_mark', 50)->nullable();    // RejectDeliveryNote

            $t->timestamps();
            $t->softDeletes();

            $t->unique(['company_id', 'invcode']);
            $t->index(['company_id', 'issued_at']);
            $t->index(['company_id', 'delivery_type_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_notes');
    }
};
