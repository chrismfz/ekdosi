<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-tenant switch for the bridge-fetch path (Slice 2). When on, the scheduled
 * `whmcs:fetch-pending` pulls the inbox feed from the ekdosi_bridge plugin
 * (resolve.php op=invoices) instead of WHMCS's native API. Default OFF — flip it
 * per tenant once you've validated the bridge feed live (the `--via-bridge` /
 * `--native` flags override it for ad-hoc runs).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $t) {
            $t->boolean('whmcs_fetch_via_bridge')->default(false)->after('whmcs_api_url');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $t) {
            $t->dropColumn('whmcs_fetch_via_bridge');
        });
    }
};
