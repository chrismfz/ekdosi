<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * «ΕΡΓΑΝΗ» tab on the Company form (super_admin), same pattern as Support /
 * Domains: `ergani_enabled` switches the whole Προσωπικό pillar (leaves,
 * calendar, employees, holidays) on for a tenant — default OFF.
 *
 * `ergani_mode` trial|production picks the ΕΡΓΑΝΗ ΙΙ endpoint. The e-ΕΦΚΑ
 * credentials are the SAME on both, so the mode alone decides whether a
 * submission is real → default trial (docs/ergani/README.md §2).
 *
 * Tenants that already use leaves (they have employees) are switched on so the
 * menu doesn't vanish under them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->boolean('ergani_enabled')->default(false);
            $table->string('ergani_mode', 12)->default('trial');
            $table->string('ergani_username', 120)->nullable();
            $table->text('ergani_password')->nullable();
        });

        DB::table('companies')
            ->whereIn('id', DB::table('employees')->select('company_id'))
            ->update(['ergani_enabled' => true]);
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['ergani_enabled', 'ergani_mode', 'ergani_username', 'ergani_password']);
        });
    }
};
