<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Attach a scanned/PDF document to an expense (Expenses polish). Private file —
 * stored on the `local` disk under `expense-documents/`, served only through a
 * signed, auth-gated download route (never a public URL). Nullable: most
 * myDATA-sourced expenses won't carry one; it's primarily for manual entries.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table): void {
            $table->string('document_path', 1024)->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table): void {
            $table->dropColumn('document_path');
        });
    }
};
