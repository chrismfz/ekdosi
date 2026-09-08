<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «Μετατροπή προσφοράς σε Υπηρεσία» provenance: which recurring service contract
 * a quote was turned into (the recurring twin of the existing
 * `converted_invoice_id`). nullOnDelete so removing a contract never deletes the
 * quote. A quote converted to a service ALSO gets `converted_invoice_id` (the
 * first invoice), so the existing isConverted() idempotency guard still covers it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotes', function (Blueprint $t) {
            $t->foreignId('converted_service_contract_id')->nullable()->after('converted_invoice_id')
                ->constrained('service_contracts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('quotes', function (Blueprint $t) {
            $t->dropConstrainedForeignId('converted_service_contract_id');
        });
    }
};
