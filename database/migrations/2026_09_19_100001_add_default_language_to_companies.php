<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-tenant default document/communication language (i18n Slice 0). Nullable:
 * null = «αυτόματο» (fall back to the app default, `el`). Used by
 * App\Support\CustomerLanguage as the fallback when a customer has NO explicit
 * language AND no usable country to derive one from — e.g. the Estonian tenant
 * (Nixpal) can default to bilingual/English while the mainland tenants stay Greek.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->string('default_language', 8)->nullable()->after('country_code');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropColumn('default_language');
        });
    }
};
