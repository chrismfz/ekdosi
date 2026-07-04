<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DOC-4 (AUDIT): the ΓΕΜΗ (General Commercial Registry) number must appear on a
 * ΓΕΜΗ-registered entity's documents (ν.4919/2022 άρθρο 22). There was no column
 * for it — operators had to stuff it into pdf_footer_text — so add a dedicated,
 * nullable field printed in the issuer block of the invoice PDF.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $t) {
            $t->string('gemi', 30)->nullable()->after('kad_primary');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $t) {
            $t->dropColumn('gemi');
        });
    }
};
