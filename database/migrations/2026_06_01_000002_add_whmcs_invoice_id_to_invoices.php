<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deterministic historical link to WHMCS. The legacy auto-invoicer
 * (FAutoInvoice.cpp) wrote the legacy ekdosi INVOICE_ID into WHMCS
 * `tblinvoices.invoiced`; the ETL kept that same INVOICE_ID as
 * `invoices.legacy_id`. So `tblinvoices.invoiced === invoices.legacy_id` is an
 * exact, non-heuristic key. `whmcs:backfill-invoice-ids` resolves it and stamps
 * the originating WHMCS invoice id here, so ekdosi knows which WHMCS invoice a
 * historical παραστατικό came from (shown on the invoice view, the customer
 * card, etc.). Nullable — only invoices cut via WHMCS get a value.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $t) {
            $t->unsignedBigInteger('whmcs_invoice_id')->nullable()->after('whmcs_pending_id');
            $t->index(['company_id', 'whmcs_invoice_id'], 'invoices_company_whmcs_invoice_idx');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $t) {
            $t->dropIndex('invoices_company_whmcs_invoice_idx');
            $t->dropColumn('whmcs_invoice_id');
        });
    }
};
