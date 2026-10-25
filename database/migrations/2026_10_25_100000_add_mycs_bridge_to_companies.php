<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «MyCS Bridge» (docs/bridges-connectors.md §12): the company's billing source becomes MyCS instead of WHMCS. MyCS
 * serves the WHMCS plugin's feed shape (`POST {mycs_url}/api/ekdosi`, same HMAC), so the inbox pipeline is the same;
 * these three are all it needs. On: the inbox reads MyCS and the MARKs go back there; WHMCS is no longer read for it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->boolean('mycs_enabled')->default(false)->after('whmcs_webhook_secret');
            $table->string('mycs_url')->nullable()->after('mycs_enabled');
            $table->text('mycs_secret')->nullable()->after('mycs_url');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['mycs_enabled', 'mycs_url', 'mycs_secret']);
        });
    }
};
