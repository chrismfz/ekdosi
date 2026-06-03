<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The stock ledger: one signed row per movement (+in / −out). Current on-hand =
 * SUM(qty_change) per product (derived, never a cached column — same discipline
 * as InvoiceBalance). Auditable, reversible. Only `products.track_stock` items
 * ever get rows.
 *
 * `source` is polymorphic (invoice_line / delivery_note_line / null=manual) so a
 * movement points back to what caused it — and so the whichever-first dedup can
 * ask "did the linked document already move this?".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('product_id')->constrained()->cascadeOnDelete();
            $t->decimal('qty_change', 12, 3);          // signed: + receipt/return, − sale/dispatch
            $t->string('reason', 20);                  // receipt/sale/purchase/return/adjustment/cancel/initial
            $t->nullableMorphs('source');              // invoice_line / delivery_note_line / null = manual
            $t->text('note')->nullable();
            $t->dateTime('occurred_at');
            $t->timestamps();

            $t->index(['company_id', 'product_id']);
            $t->index(['company_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
