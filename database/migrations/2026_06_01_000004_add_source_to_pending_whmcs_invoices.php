<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 0 (Bridges/Connectors): each staged document records WHICH source it
 * came from. Default 'whmcs' so existing rows + the live pipeline are unchanged.
 *
 * Phase 1 will add a `billing_connection_id` FK (to distinguish two shops of the
 * same source) and likely rename the table to `pending_external_invoices`; for
 * now a single source key is enough (WHMCS is the only source).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pending_whmcs_invoices', function (Blueprint $t) {
            $t->string('source', 40)->default('whmcs')->after('company_id');
            $t->index(['company_id', 'source']);
        });
    }

    public function down(): void
    {
        Schema::table('pending_whmcs_invoices', function (Blueprint $t) {
            $t->dropIndex(['company_id', 'source']);
            $t->dropColumn('source');
        });
    }
};
