<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Slice 1 (myDATA v2.0.2 / DEP-001): denormalised cache of the `deliveryReturnMark`
 * returned by ConfirmDeliveryReturn — the durable attempt-record the delivery-note
 * family was HELD for. Mirrors `transfer_mark` (RegisterTransfer) and `outcome_mark`
 * (ConfirmDeliveryOutcome); written ONLY by DeliveryLifecycleService via forceFill
 * (a guarded lifecycle-cache column). The authoritative row still lives in
 * `delivery_marks` (action CONFIRM_RETURN).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_notes', function (Blueprint $t) {
            $t->string('return_mark', 50)->nullable()->after('outcome_mark'); // ConfirmDeliveryReturn
        });
    }

    public function down(): void
    {
        Schema::table('delivery_notes', function (Blueprint $t) {
            $t->dropColumn('return_mark');
        });
    }
};
