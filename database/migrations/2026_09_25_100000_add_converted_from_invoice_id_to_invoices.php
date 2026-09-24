<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «Μετατροπή σε φορολογικό» — docs/non-billable-services.md §5.
 *
 * `invoices.converted_from_invoice_id`: on the FISCAL draft made from an informal
 * (non-fiscal) document, the informal source. The informal document stays as the
 * trace and reads the reverse (Invoice::conversions()). One write links both ways.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('converted_from_invoice_id')->nullable()->after('reissued_from_invoice_id')
                ->constrained('invoices')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('converted_from_invoice_id');
        });
    }
};
