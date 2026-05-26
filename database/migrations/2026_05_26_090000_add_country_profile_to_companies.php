<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-country support on `companies`.
 *
 * - country_code:       ISO-3166-1 alpha-2 (e.g. "GR", "EE").
 * - einvoice_provider:  selects the IssueInvoice submitter.
 *                       "gr-mydata" → firebed/aade-mydata
 *                       "ee-peppol" → Estonian PEPPOL stub (placeholder)
 *                       "none"      → no upstream submission (PDF only)
 *
 * Stored as a small VARCHAR rather than a DB enum so we can add
 * providers (Bulgaria, Romania, …) without ALTER TYPE on MariaDB.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $t) {
            $t->string('country_code', 2)->default('GR')->after('slug');
            $t->string('einvoice_provider', 20)->default('gr-mydata')->after('country_code');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $t) {
            $t->dropColumn(['country_code', 'einvoice_provider']);
        });
    }
};
