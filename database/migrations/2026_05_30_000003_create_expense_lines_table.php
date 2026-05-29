<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Expense lines (γραμμές εξόδων) — the twin of `invoice_lines`, one row per
 * `<invoiceDetails>` line of a supplier doc (RequestDocs returns full lines,
 * multi-line docs exist → per-line is the locked decision).
 *
 * ⚠️ Zero-VAT on the expense side comes in TWO shapes that we store VERBATIM
 * (per the E0 sample findings) and do NOT run through the sales-side
 * `vatCategoryFor()` rule (which throws on 0% without a reason — G4):
 *   - vatCategory 7 + a `vat_exemption_category` (§8.3 reason, e.g. 16 =
 *     reverse-charge άρθ.39α), vatAmount 0;
 *   - vatCategory 8 with netValue 0 + vatAmount 0 and NO exemption.
 * Both mean input-VAT 0. So `vat_category` + `vat_exemption_category` are kept
 * exactly as AADE sent them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_lines', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('expense_id')->constrained()->cascadeOnDelete();

            $t->unsignedInteger('line_number')->nullable();
            $t->string('item_code', 191)->nullable();
            $t->string('item_descr', 256)->nullable();

            $t->decimal('quantity', 9, 3)->nullable();
            $t->string('measurement_unit', 15)->nullable();    // §8.13 (when present)

            $t->decimal('net_value', 14, 2)->default(0);
            $t->unsignedTinyInteger('vat_category')->nullable();          // §8.2 (1..8) — verbatim
            $t->unsignedTinyInteger('vat_exemption_category')->nullable(); // §8.3 (1..31) — present for 0%
            $t->decimal('vat_amount', 14, 2)->default(0);

            // Expense classification (E5 — SendExpensesClassification), per line.
            $t->string('classification_type', 20)->nullable();    // E3_* code
            $t->string('classification_category', 30)->nullable(); // category2_x / category2_95 …

            $t->text('notes')->nullable();
            $t->timestamps();

            $t->index('expense_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_lines');
    }
};
