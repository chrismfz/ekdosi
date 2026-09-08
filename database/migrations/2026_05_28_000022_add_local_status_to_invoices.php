<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Local (business-intent) status for invoices, ORTHOGONAL to mydata_state.
 *
 *   local_status  : draft | active | cancelled   — operator intent, editable
 *   mydata_state  : null | VALID | CANCELLED      — the AADE truth (unchanged)
 *
 * The pair is a reconciliation matrix (e.g. cancelled-local + VALID-at-AADE
 * = "still needs a myDATA cancel"). local_status never overwrites the AADE
 * record, so we can't desync from the tax authority.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('local_status', 16)->default('draft')->after('mydata_state');
            $table->text('cancel_reason')->nullable()->after('local_status');
            $table->index(['company_id', 'local_status']);
        });

        // Backfill from the existing AADE state: filed → active,
        // AADE-cancelled → cancelled, never-filed → draft.
        DB::table('invoices')->where('mydata_state', 'VALID')->update(['local_status' => 'active']);
        DB::table('invoices')->where('mydata_state', 'CANCELLED')->update(['local_status' => 'cancelled']);
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'local_status']);
            $table->dropColumn(['local_status', 'cancel_reason']);
        });
    }
};
