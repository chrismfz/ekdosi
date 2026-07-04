<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * OPS-5 (AUDIT): the safe default for an automated-backup policy is `full` — one
 * that includes the books (invoices/payments/marks), not just config/lookups.
 * The column shipped defaulting to `settings_setup`, so a tenant enabling
 * backups with defaults got a false DR sense (config backed up, books not).
 *
 * Only flips the DEFAULT for FUTURE inserts. Existing rows are left as-is (a
 * tenant may have deliberately chosen a config-only bundle) — ops:health now
 * warns loudly about any enabled backup whose bucket≠full instead of silently
 * rewriting intent.
 *
 * Raw ALTER, MariaDB/MySQL only: the sqlite test DB doesn't need a stored default
 * (rows are created with an explicit bucket), and a portable ->change() would
 * rebuild the table needlessly.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE company_backup_settings ALTER COLUMN bucket SET DEFAULT 'full'");
        }
    }

    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE company_backup_settings ALTER COLUMN bucket SET DEFAULT 'settings_setup'");
        }
    }
};
