<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MON-1 (AUDIT): link a credit-note line back to the ORIGINAL invoice line it
 * credits. Without it, `return_invoice_extras.qty_returned` was an
 * increment-only counter with no way to know which original line a cancelled
 * credit note had bumped — so cancelling a credit note permanently consumed the
 * returned quantity and the original could never be re-credited.
 *
 * Nullable + self-referencing to invoice_lines. NOT set for ETL-imported legacy
 * credit notes (the legacy schema tracks returns per-line, not per-credit-line),
 * so the qty_returned recompute deliberately leaves legacy-only lines untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_lines', function (Blueprint $t) {
            // nullOnDelete: if the original line is ever hard-deleted the credit
            // line survives (it's a legal document) with the link cleared.
            $t->foreignId('original_line_id')
                ->nullable()
                ->after('product_id')
                ->constrained('invoice_lines')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('invoice_lines', function (Blueprint $t) {
            $t->dropConstrainedForeignId('original_line_id');
        });
    }
};
