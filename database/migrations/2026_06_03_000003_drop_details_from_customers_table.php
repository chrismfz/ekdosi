<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3: drop `customers.details`. Its data now lives in `notes` (Phase 2),
 * the importers write there (BackupNoteSync), and nothing reads the column.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('customers', 'details')) {
            Schema::table('customers', function (Blueprint $t) {
                $t->dropColumn('details');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('customers', 'details')) {
            Schema::table('customers', function (Blueprint $t) {
                $t->text('details')->nullable();
            });
        }
    }
};
