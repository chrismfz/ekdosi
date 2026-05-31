<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Self-declared expenses (E8). Until now `expenses` only held documents OTHERS
 * filed against us (RequestDocs, source='sync'). But on RequestTransmittedDocs
 * — what WE declared — there are also non-income docs we file ourselves:
 * retail expenses (13.x), intra-community / VIES / ΕΦΚΑ (14.x) and accounting
 * entries (μισθοδοσία / πάγια / τακτοποιήσεις, 17.x). These belong in Έξοδα too
 * for a full ΦΠΑ/Ε3 picture, but must stay visually distinct from supplier docs
 * AND from each other (a €5k payroll is not a "τιμολόγιο").
 *
 *   - source='self_declared' (new ExpenseSource case) separates them from the
 *     supplier 'sync' docs — a dedicated tab/filter in the resource.
 *   - `category` is the coarse economic bucket key from
 *     Codes::selfDeclaredVatCategory() (payroll / depreciation / social_security
 *     / intracommunity / retail_expense / adjustments / other) so the UI can
 *     split "real" expense invoices (13/14) from accounting entries (17.x)
 *     without a second table.
 *
 * Nullable: supplier-sync rows leave `category` null (their type already tells
 * the story).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $t): void {
            $t->string('category', 30)->nullable()->after('source');
            $t->index(['company_id', 'source', 'category']);
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $t): void {
            $t->dropIndex(['company_id', 'source', 'category']);
            $t->dropColumn('category');
        });
    }
};
