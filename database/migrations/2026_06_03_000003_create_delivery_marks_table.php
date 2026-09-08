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
            // Nullable: a lifecycle event (REGISTER_TRANSFER/CONFIRM_OUTCOME) is
            // still worth auditing even in the unlikely case AADE returns Success
            // without a *Mark element — better the audit row survives than a
            // NOT-NULL violation drops it. The issue INSERT always carries a mark.
            $t->string('mark', 50)->nullable();
            // INSERT / REGISTER_TRANSFER / CONFIRM_OUTCOME / REJECT / CANCEL
            $t->string('mydata_action', 30)->nullable();
            $t->string('invoice_url', 1500)->nullable();  // qrUrl from the response
            $t->mediumText('request')->nullable();
            $t->mediumText('response')->nullable();
            $t->date('mark_date')->nullable();
            $t->time('mark_time')->nullable();   // wall-clock of the MARK (twin of mydata_marks.mark_time)
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
