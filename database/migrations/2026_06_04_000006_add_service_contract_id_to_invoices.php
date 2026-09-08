<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Provenance: which service contract staged this invoice (renewal). Nullable —
 * the vast majority of invoices are not from a contract. `nullOnDelete` so
 * removing a contract never deletes the legal documents it produced. Used for
 * idempotency (don't re-stage the same period) and the contract↔invoice history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $t) {
            $t->foreignId('service_contract_id')->nullable()->after('whmcs_pending_id')
                ->constrained('service_contracts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $t) {
            $t->dropConstrainedForeignId('service_contract_id');
        });
    }
};
