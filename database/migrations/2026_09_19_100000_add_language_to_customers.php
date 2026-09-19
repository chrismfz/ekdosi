<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-customer communication-language override (i18n Slice 0). Nullable:
 * null = «αυτόματο» (derive from the recipient's country — GR→el, foreign→both,
 * else the company default). An explicit 'el'|'en'|'both' forces that customer's
 * documents + mails to that language regardless of country (e.g. a Greek-registered
 * client who wants English invoices). Resolved by App\Support\CustomerLanguage.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->string('language', 8)->nullable()->after('country_code');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropColumn('language');
        });
    }
};
