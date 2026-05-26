<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add `invoices.mydata_type` as a snapshot column.
 *
 * At submission time the MyDataSubmitter copies the value from
 * `$invoice->invoiceType->mydata_type` into this column. The invoice
 * type's mydata_type can change later (admin reclassifies the series,
 * AADE adds a new type code, etc.) — but the FILED invoice must keep
 * the classification it was actually filed under. Reading
 * mydata_type through the relation would silently shift historical
 * invoices' classification on every admin edit.
 *
 * Matches the same snapshot pattern used for the party fields
 * (address1, vat_no, occupation, etc. on the invoices table) which
 * are also frozen at issue-time.
 *
 * Stays nullable for two reasons:
 *   - draft / NullSubmitter invoices have no filed type yet
 *   - legacy ETL-imported invoices may not have a corresponding
 *     invoice_type with a mydata_type set
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $t) {
            $t->string('mydata_type', 5)->nullable()->after('mydata_url');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $t) {
            $t->dropColumn('mydata_type');
        });
    }
};
