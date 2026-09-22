<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #8: per-tenant opt-in for the gentle weekly background re-check of customers'
 * ΑΦΜ registry status (customers:refresh-aade-status). Default OFF — GSIS has
 * daily quotas, so the sweep is bounded AND opt-in. The knob lives next to the
 * GSIS credentials (Company form → «AADE registry (GSIS)» tab). The on-demand
 * «Διασταύρωση ΑΦΜ με ΑΑΔΕ» button is unaffected by this flag.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->boolean('aade_status_auto_refresh')->default(false)->after('gsis_password');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropColumn('aade_status_auto_refresh');
        });
    }
};
