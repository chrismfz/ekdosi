<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-document PDF language ('el'|'en'|'both'). NULL = auto: resolved from the
 * recipient's country (GR → Greek, foreign → bilingual GR/EN) by
 * {@see \App\Support\Pdf\PdfLabels::resolveLanguage()}. Drives only the PDF
 * field labels — never the legal content/amounts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->string('language', 8)->nullable()->after('notes');
        });
        Schema::table('quotes', function (Blueprint $table): void {
            $table->string('language', 8)->nullable()->after('customer_notes');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', fn (Blueprint $t) => $t->dropColumn('language'));
        Schema::table('quotes', fn (Blueprint $t) => $t->dropColumn('language'));
    }
};
