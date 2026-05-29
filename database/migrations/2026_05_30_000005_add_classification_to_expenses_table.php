<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-document expense classification (E5). The operator assigns one
 * classification (§8.x E3 type + category2_x) to the whole expense doc; it
 * lives on the header here.
 *
 * `expense_lines.classification_type/category` (E1) stay for a FUTURE per-line
 * AADE `SendExpensesClassification` submit — populated from this header choice
 * the same way the sales side applies the invoice-type income class to every
 * line. For now classification is LOCAL ONLY (reports / ΦΠΑ), not filed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $t): void {
            // §8.x ExpenseClassificationType (E3_*) and ExpenseClassificationCategory
            // (category2_*) — stored as the AADE string codes.
            $t->string('classification_type', 20)->nullable()->after('classification_state');
            $t->string('classification_category', 30)->nullable()->after('classification_type');
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $t): void {
            $t->dropColumn(['classification_type', 'classification_category']);
        });
    }
};
