<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Auto-classification rules for inbound expenses (#5): «προμηθευτής (+ προαιρετικός
 * τύπος) → χαρακτηρισμός». When an expense is imported / on demand, the first
 * matching rule stamps its myDATA expense classification (E3 type + category2_x)
 * so the operator doesn't hand-classify every recurring supplier doc.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_classification_rules', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();

            // Match key: the supplier's ΑΦΜ (always present on a myDATA expense),
            // optionally narrowed to one myDATA invoice type (e.g. 14.30).
            $t->string('supplier_afm');
            $t->string('invoice_type')->nullable();

            // Target: the per-document expense classification stamped on a match.
            $t->string('classification_type');       // E3_* expense code (§8.x)
            $t->string('classification_category');    // category2_x

            $t->string('label')->nullable();          // operator note
            $t->boolean('is_active')->default(true);
            // Higher wins when several rules match; a type-specific rule also beats
            // the generic (null invoice_type) one — resolved in ExpenseClassifier.
            $t->integer('priority')->default(0);
            $t->timestamps();

            $t->index(['company_id', 'supplier_afm']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_classification_rules');
    }
};
