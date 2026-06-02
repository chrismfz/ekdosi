<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Generalise the import-runs log from Firebird-only to any data source.
 * The resource is renamed «Data Import» in the UI; the table keeps its name.
 *
 * `source`            — 'firebird' (default, the gbak/.fdb path) | 'epsilon'
 *                       (Epsilon Smart JSON exports).
 * `source_files_json` — for multi-file sources (Epsilon uploads Customers /
 *                       Items / Services as separate JSON files): the per-type
 *                       stored paths. Null for single-file Firebird runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('firebird_import_runs', function (Blueprint $table) {
            $table->string('source', 20)->default('firebird')->after('company_id');
            $table->json('source_files_json')->nullable()->after('uploaded_path');
        });
    }

    public function down(): void
    {
        Schema::table('firebird_import_runs', function (Blueprint $table) {
            $table->dropColumn(['source', 'source_files_json']);
        });
    }
};
