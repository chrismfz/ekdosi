<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WHMCS mass-pay handling. A «συγκεντρωτικό» WHMCS payment invoice references N
 * child invoices (relids), no VAT of its own — it must never be filed as a sale.
 * The operator resolves it either by CONSOLIDATING the children's real lines into
 * one παραστατικό, or by handling them PER-ORDER. This adds:
 *
 *  - masspay_parent_id: on a CHILD pending row, points at the mass-pay row it
 *    belongs to (the «Ανάλυση σε επιμέρους» path links each child here, for the
 *    grouped UI and the «all children filed → resolve the parent» sweep).
 *
 * The «Ενοποίηση» path instead rewrites the mass-pay row in place (merged lines)
 * and records the covered child ids in its payload — no schema needed for that.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pending_whmcs_invoices', function (Blueprint $table): void {
            $table->unsignedBigInteger('masspay_parent_id')->nullable()->after('invoice_id');

            $table->index(['company_id', 'masspay_parent_id'], 'pwi_masspay_parent_idx');
            // Self-reference: if the parent mass-pay row is deleted, orphan the link
            // rather than cascade-delete the (legally significant) child rows.
            $table->foreign('masspay_parent_id', 'pwi_masspay_parent_fk')
                ->references('id')->on('pending_whmcs_invoices')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pending_whmcs_invoices', function (Blueprint $table): void {
            $table->dropForeign('pwi_masspay_parent_fk');
            $table->dropIndex('pwi_masspay_parent_idx');
            $table->dropColumn('masspay_parent_id');
        });
    }
};
