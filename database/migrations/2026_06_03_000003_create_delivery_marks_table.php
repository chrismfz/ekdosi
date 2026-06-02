<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Legal audit trail of every myDATA call for a delivery note — twin of
 * `mydata_marks`. Beyond the issue INSERT it also records the e-transport
 * lifecycle events (RegisterTransfer / ConfirmDeliveryOutcome / Reject /
 * Cancel), each with its own returned MARK + full request/response XML. Keep
 * it whole — the byte-exact transmitted record is the legal value.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_marks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->unsignedInteger('legacy_id')->nullable();
            $t->foreignId('delivery_note_id')->nullable()->constrained()->cascadeOnDelete();
            $t->string('mark', 50);
            // INSERT / REGISTER_TRANSFER / CONFIRM_OUTCOME / REJECT / CANCEL
            $t->string('mydata_action', 30)->nullable();
            $t->string('invoice_url', 1500)->nullable();  // qrUrl from the response
            $t->mediumText('request')->nullable();
            $t->mediumText('response')->nullable();
            $t->date('mark_date')->nullable();
            $t->timestamp('mark_time')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'legacy_id']);
            $t->index('delivery_note_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_marks');
    }
};
