<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The remaining myDATA taxesTotals taxTypes beyond withholding (G1, taxType=1):
 *   2 = Τέλη (fees, §8.5)            — e.g. τέλος ανθεκτικότητας/διαμονής
 *   3 = Λοιποί φόροι (otherTaxes, §8.6)
 *   4 = Χαρτόσημο (stamp duty, §8.7)
 *   5 = Κρατήσεις (deductions, §8.8)
 *
 * Each carries an amount + a category code (mirrors withhold_amount/withhold_category).
 * The submitter emits a taxesTotals block + sets the matching summary total when the
 * amount is > 0. All nullable/zero → standard invoices are unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->decimal('fees_amount', 14, 2)->nullable()->after('withhold_category');
            $table->unsignedSmallInteger('fees_category')->nullable()->after('fees_amount');
            $table->decimal('other_taxes_amount', 14, 2)->nullable()->after('fees_category');
            $table->unsignedSmallInteger('other_taxes_category')->nullable()->after('other_taxes_amount');
            $table->decimal('stamp_duty_amount', 14, 2)->nullable()->after('other_taxes_category');
            $table->unsignedSmallInteger('stamp_duty_category')->nullable()->after('stamp_duty_amount');
            $table->decimal('deductions_amount', 14, 2)->nullable()->after('stamp_duty_category');
            $table->unsignedSmallInteger('deductions_category')->nullable()->after('deductions_amount');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn([
                'fees_amount', 'fees_category',
                'other_taxes_amount', 'other_taxes_category',
                'stamp_duty_amount', 'stamp_duty_category',
                'deductions_amount', 'deductions_category',
            ]);
        });
    }
};
