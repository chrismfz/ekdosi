<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «Φορολογικά» month-by-month table: the Ε3 snapshot also keeps AADE's figures
 * split by the entries' IssueDate month — {"1": [{type, category, value}], …}.
 * Nullable: a snapshot fetched before this column simply has no month split
 * (the page falls back to the local book per month until the next refresh).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('e3_year_snapshots', function (Blueprint $table) {
            $table->json('monthly')->nullable()->after('rows');
        });
    }

    public function down(): void
    {
        Schema::table('e3_year_snapshots', function (Blueprint $table) {
            $table->dropColumn('monthly');
        });
    }
};
