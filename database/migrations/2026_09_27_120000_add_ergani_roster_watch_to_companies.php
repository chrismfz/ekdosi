<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ΕΡΓΑΝΗ «φύλακας προσωπικού» (`ergani:watch`, weekly, read-only EX_BASE_05 from
 * production): the last differences between the ΕΡΓΑΝΗ roster and the local
 * Εργαζόμενοι — minimal fields only (ΑΦΜ, name, local id). Nothing is changed
 * automatically; the admins are told when the picture changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->json('ergani_roster_diff')->nullable();
            $table->timestamp('ergani_roster_checked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('companies', fn (Blueprint $table) => $table->dropColumn(['ergani_roster_diff', 'ergani_roster_checked_at']));
    }
};
