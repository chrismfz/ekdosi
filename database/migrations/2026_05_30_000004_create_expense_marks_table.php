<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Expense marks (έξοδα — νομικό ίχνος) — the twin of `mydata_marks`, the
 * byte-exact audit trail of every myDATA call on the expense side
 * (`RequestDocs` pulls, `SendExpensesClassification` posts, cancellations).
 *
 * Like the sales `mydata_marks`, the full request + response XML is preserved
 * VERBATIM per row — do not truncate/normalise; this is the source of truth
 * and the `expenses.mydata_*` columns are just its latest-state cache.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_marks', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('expense_id')->nullable()->constrained()->cascadeOnDelete();

            $t->string('mark', 50)->nullable();
            $t->string('mydata_action', 40)->nullable();  // RequestDocs / SendExpensesClassification / CANCEL
            $t->mediumText('request')->nullable();         // full submitted/queried XML
            $t->mediumText('response')->nullable();        // full AADE response XML
            $t->date('mark_date')->nullable();
            // TIME (HH:MM:SS), not TIMESTAMP — mirrors the mydata_marks fix
            // (2026_05_27_000002). A bare time stored in a TIMESTAMP column
            // forces a 0000-00-00 date (STRICT mode crash) or a synthesised
            // date that breaks time comparisons. Left uncast on the model.
            $t->time('mark_time')->nullable();
            $t->timestamps();

            $t->index('expense_id');
            $t->index(['company_id', 'mark']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_marks');
    }
};
