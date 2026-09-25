<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «Ίδια μέσα» (we deliver with our own vehicle) — Β' Φάση Ψηφιακής Διακίνησης (12/10/2026).
 *
 * - `non_obligated_recipient` (delivery_notes + ΤΔΑ invoices): myDATA header
 *   nonObligatedRecipient — the recipient is not obliged to confirm (private person / no
 *   ERP), so OUR carrier FULL outcome closes the movement (AADE Completed).
 * - `invoices.outcome_mark`: the ConfirmDeliveryOutcome MARK for a ΤΔΑ (delivery_notes
 *   already has it) — written only by DeliveryLifecycleService, like the other *_mark cache.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_notes', function (Blueprint $table) {
            $table->boolean('non_obligated_recipient')->default(false);
        });
        Schema::table('invoices', function (Blueprint $table) {
            $table->boolean('non_obligated_recipient')->default(false);
            $table->string('outcome_mark', 50)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('delivery_notes', function (Blueprint $table) {
            $table->dropColumn('non_obligated_recipient');
        });
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['non_obligated_recipient', 'outcome_mark']);
        });
    }
};
