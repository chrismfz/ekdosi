<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `customer_balance_snapshot` — the customer's TOTAL running balance (Καρτέλα
 * «υπόλοιπο», incl. on-account credit) captured at the moment this invoice was
 * ISSUED (draft→active). Lets the invoice PDF print a legacy-true «Νέο υπόλοιπο»
 * block (Προηγούμενο / αυτό το παραστατικό / Νέο) that stays STABLE on reprint —
 * a live recompute would drift as later invoices/payments land.
 *
 * Snapshot semantics: the balance AFTER this invoice (it's already active when we
 * capture). Προηγούμενο is derived at render = snapshot − this invoice's own
 * contribution. NULL = not captured (imported/legacy rows, cash-term, drafts) →
 * the PDF block is simply omitted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->decimal('customer_balance_snapshot', 14, 2)->nullable()->after('payable_total');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', fn (Blueprint $t) => $t->dropColumn('customer_balance_snapshot'));
    }
};
