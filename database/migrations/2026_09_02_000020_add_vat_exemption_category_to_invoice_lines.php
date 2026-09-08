<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MYD-007: the per-line §8.3 VAT-exemption reason snapshot.
 *
 * Until now a 0% line filed the ONE tenant-wide 0%-VatCategory reason, and >1 such
 * category threw ("ambiguous"). That is wrong: the reason differs by case (intra-EU
 * service → 4, intra-EU goods → 14, export → 8, …), so it must be captured and
 * snapshotted PER LINE — like vat_percent is. New 0% lines record the chosen reason
 * here; the submitter reads it. Nullable + additive: existing lines stay null and
 * fall back to the legacy single-category resolver (back-compat), and non-0% lines
 * never set it.
 *
 * unsignedTinyInteger: §8.3 codes are 1–31.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('invoice_lines', 'vat_exemption_category')) {
            return;
        }

        Schema::table('invoice_lines', function (Blueprint $t) {
            $t->unsignedTinyInteger('vat_exemption_category')->nullable()->after('vat_percent');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_lines', function (Blueprint $t) {
            $t->dropColumn('vat_exemption_category');
        });
    }
};
