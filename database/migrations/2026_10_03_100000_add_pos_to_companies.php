<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Point of Sale (docs/woocommerce-bridge-plan.md §11): a per-company kill-switch
 * (same pattern as support/ergani) + which receipt series (11.1 ΑΛΠ) and which
 * cash payment method a till sale uses.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->boolean('pos_enabled')->default(false);
            $table->foreignId('pos_invoice_type_id')->nullable()->constrained('invoice_types')->nullOnDelete();
            $table->foreignId('pos_payment_method_id')->nullable()->constrained('payment_methods')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pos_payment_method_id');
            $table->dropConstrainedForeignId('pos_invoice_type_id');
            $table->dropColumn('pos_enabled');
        });
    }
};
