<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ενιαία Κωδικοποίηση Ειδών — Β' Φάση Ψηφιακής Διακίνησης (Α.1094/2026), υποχρεωτική από 1/1/2027.
 *
 * - `products.taric_code`: the item's code, stored NORMALISED to the 10 characters myDATA's
 *   TaricNo demands (a Συνδυασμένη Ονοματολογία 8-digit code gets «00» — the XML schema
 *   rejects any other length, AADE sandbox 2026-09-25 [101]).
 * - `taric_code` + `item_code` on invoice_lines / delivery_note_lines: SNAPSHOTS taken when
 *   the line is created (or its product changes), so a filed document never changes when
 *   the product is edited later. item_code = the product SKU (myDATA itemCode, ≤50).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('taric_code', 10)->nullable();
        });
        foreach (['invoice_lines', 'delivery_note_lines'] as $t) {
            Schema::table($t, function (Blueprint $table) {
                $table->string('taric_code', 10)->nullable();
                $table->string('item_code', 50)->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('taric_code');
        });
        foreach (['invoice_lines', 'delivery_note_lines'] as $t) {
            Schema::table($t, function (Blueprint $table) {
                $table->dropColumn(['taric_code', 'item_code']);
            });
        }
    }
};
