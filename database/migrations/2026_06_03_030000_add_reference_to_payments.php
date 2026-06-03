<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Groups the Payment rows that make up one «έμβασμα/είσπραξη» (one received
 * amount allocated across several invoices + an on-account remainder). Lets the
 * Καρτέλα show the receipt as one logical entry (Phase 3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $t) {
            $t->string('reference', 40)->nullable()->after('notes');
            $t->index(['company_id', 'reference']);
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $t) {
            $t->dropIndex(['company_id', 'reference']);
            $t->dropColumn('reference');
        });
    }
};
