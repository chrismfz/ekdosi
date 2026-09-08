<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Denormalized money-status cache on `invoices`, the same pattern as
 * the `mydata_*` cache columns: written ONLY by App\Services\InvoiceBalance
 * (never mass-assigned), recomputed in-transaction when payments or
 * credit notes change. Nullable = "never computed yet" (treated as 0 /
 * recomputed by the invoices:recompute-balances backfill). NOT touched
 * by the ETL upsert, so re-imports never clobber a fresh recompute.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $t) {
            $t->decimal('paid_total', 14, 2)->nullable()->after('gross_total');
            $t->decimal('credited_total', 14, 2)->nullable()->after('paid_total');
            // paid | partial | unpaid | credited | overpaid
            $t->string('payment_status', 12)->nullable()->after('credited_total');
            // The original invoice a credit note is issued against. A
            // DEDICATED column (not conv_invoice_id, which the ETL
            // rewrites every re-import). Nullable: against-original is
            // the norm, standalone credit notes allowed. The credit-note
            // ISSUE flow lands in a follow-up PR; the column + the
            // InvoiceBalance credited query live here so the money model
            // is complete from day one.
            $t->foreignId('credited_invoice_id')->nullable()->after('conv_invoice_id')
                ->constrained('invoices')->nullOnDelete();
            $t->index(['company_id', 'payment_status']);
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $t) {
            $t->dropIndex(['company_id', 'payment_status']);
            $t->dropConstrainedForeignId('credited_invoice_id');
            $t->dropColumn(['paid_total', 'credited_total', 'payment_status']);
        });
    }
};
