<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Leads L1: a Προσφορά can be issued to a lead BEFORE it becomes a customer
 * (quotes already allow a null customer + a party snapshot). On conversion the
 * lead's quotes get their `customer_id` filled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotes', function (Blueprint $t) {
            $t->foreignId('lead_id')->nullable()->after('customer_id')->constrained('leads')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('quotes', function (Blueprint $t) {
            $t->dropConstrainedForeignId('lead_id');
        });
    }
};
