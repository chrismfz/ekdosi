<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T-1c (timologia v2): the guided multi-party split.
 *
 * A single WHMCS invoice that mixes billing parties is split into N ekdosi
 * invoices — one per party. That's a many-invoices ↔ one-pending-row
 * relationship, which the existing pending_whmcs_invoices.invoice_id (a single
 * FK, for the 1:1 path) can't express. Each split invoice instead carries a
 * back-reference to the pending row it came from.
 *
 * nullOnDelete: a pending row is never hard-deleted in practice (it's an audit
 * record), but if one ever were, the split invoices survive with a null
 * back-reference rather than cascading away legally-significant documents.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('whmcs_pending_id')
                ->nullable()
                ->after('credited_invoice_id')
                ->constrained('pending_whmcs_invoices')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('whmcs_pending_id');
        });
    }
};
