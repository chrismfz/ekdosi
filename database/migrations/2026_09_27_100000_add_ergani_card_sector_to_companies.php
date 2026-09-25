<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ΕΡΓΑΝΗ «φύλακας κάρτας» (`ergani:watch`): the last known EX_BASE_01.IsInCardSector
 * of the employer (read weekly from PRODUCTION, read-only). null = never checked.
 * The day it flips to true the company must start the Ψηφιακή Κάρτα Εργασίας —
 * and the ΕΡΓΑΝΗ schedule/calendar services (EX_BASE_07/08) become available.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->boolean('ergani_card_sector')->nullable();
            $table->timestamp('ergani_card_sector_checked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('companies', fn (Blueprint $table) => $table->dropColumn(['ergani_card_sector', 'ergani_card_sector_checked_at']));
    }
};
