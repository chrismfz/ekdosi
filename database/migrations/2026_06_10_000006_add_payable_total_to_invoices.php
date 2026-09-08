<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `payable_total` — the real COLLECTIBLE amount (what the customer actually pays /
 * what we'll receive): gross_total (net+VAT) PLUS the myDATA additional-tax
 * adjustment [208] — fees + stamp + other − deductions − withholding (except the
 * informational §8.4 withholding categories 8/9/10). Equals the AADE
 * `totalGrossValue` + the PDF «Πληρωτέο».
 *
 * Kept SEPARATE from gross_total on purpose: gross_total stays «net+VAT» (revenue /
 * turnover / VAT reporting), while payable_total is the basis for owed / balance /
 * receivables. NULL on old rows → readers fall back to gross_total + a live
 * adjustment via Invoice::payableTotal().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->decimal('payable_total', 14, 2)->nullable()->after('gross_total');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', fn (Blueprint $t) => $t->dropColumn('payable_total'));
    }
};
