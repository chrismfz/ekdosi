<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Άτυπη (μη φορολογική) σειρά — docs/non-billable-services.md.
 *
 * `invoice_types.is_informal`: documents of this series are NOT tax documents
 * (tests, our own internal services). They go through the normal lifecycle
 * (staging → draft → finalize → service/domain renewal) but never reach myDATA,
 * never count in any money/VAT total (InvoiceScope::live), and print «ΑΤΥΠΟ».
 *
 * `customers.default_invoice_type_id`: the series new invoices / services of this
 * customer start with (e.g. our own company → the informal «ΕΣΩ» series).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_types', function (Blueprint $table) {
            $table->boolean('is_informal')->default(false)->after('is_delivery_note');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->foreignId('default_invoice_type_id')->nullable()->after('payment_method_id')
                ->constrained('invoice_types')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('default_invoice_type_id');
        });

        Schema::table('invoice_types', function (Blueprint $table) {
            $table->dropColumn('is_informal');
        });
    }
};
