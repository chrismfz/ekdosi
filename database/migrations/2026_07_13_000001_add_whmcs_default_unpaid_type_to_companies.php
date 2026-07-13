<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Paid/unpaid-aware WHMCS bridge (Phase 1): a per-tenant DEFAULT type for
 * UNPAID WHMCS invoices, alongside the paid invoice/receipt defaults.
 *
 *   companies.whmcs_default_unpaid_type_id  (nullable FK)
 *     The «επί πιστώσει» invoice type the «Δημιουργία Παραστατικού» draft
 *     pre-selects when the WHMCS invoice is UNPAID (e.g. a public-sector / Α.Ε.
 *     customer that wants a τιμολόγιο FIRST, then pays). Such a type points at a
 *     payment method with due_days > 0, so the issued invoice correctly stays an
 *     OPEN receivable until paid — unlike the paid defaults (cash-term, settled
 *     at issue). Null → fall back to the paid invoice-type default. nullOnDelete
 *     so deleting the type doesn't cascade the tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->foreignId('whmcs_default_unpaid_type_id')
                ->nullable()
                ->after('whmcs_default_receipt_type_id')
                ->constrained('invoice_types')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('whmcs_default_unpaid_type_id');
        });
    }
};
