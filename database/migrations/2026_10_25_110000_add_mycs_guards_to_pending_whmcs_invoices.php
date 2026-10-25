<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «MyCS Bridge» review (docs/bridges-connectors.md §12):
 * - `reopen_history`: a filed row whose document MyCS refused (the invoice changed there) and that was cancelled at
 *   AADE is reopened to be read again; each time, the filed snapshot (payload, MARK, document, filed_at) is kept
 *   here for the audit — the cancelled document itself stays as it was.
 * - `source_conflict`: the same invoice number came from WHMCS and from MyCS for another client. Once the operator
 *   released it, it is not held again, and its MARK never goes to MyCS (it would document the other client's invoice).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pending_whmcs_invoices', function (Blueprint $table) {
            $table->json('reopen_history')->nullable();
            $table->boolean('source_conflict')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('pending_whmcs_invoices', function (Blueprint $table) {
            $table->dropColumn(['reopen_history', 'source_conflict']);
        });
    }
};
