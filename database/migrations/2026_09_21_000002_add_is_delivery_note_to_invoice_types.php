<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Combined ΤΔΑ — Slice 3a (`docs/combined-tda-design.md` §5/§8).
 *
 * A «ΤΔΑ» invoice_type PRE-SETS `is_delivery_note` on new invoices of that type;
 * the per-invoice `invoices.is_delivery_note` stays authoritative. Default false
 * → every existing type is unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_types', function (Blueprint $t) {
            $t->boolean('is_delivery_note')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('invoice_types', function (Blueprint $t) {
            $t->dropColumn('is_delivery_note');
        });
    }
};
