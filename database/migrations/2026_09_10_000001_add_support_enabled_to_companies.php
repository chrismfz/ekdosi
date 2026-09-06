<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-tenant kill-switch for the whole Support/Ticket pillar (Πυλώνας E).
 * Default OFF → the Support Cluster, its config screens and the portal «Τα
 * αιτήματά μου» are all hidden until a super-admin turns it on for the tenant.
 * Same shape as ai_assistant_enabled / whmcs_third_party_enabled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $t) {
            $t->boolean('support_enabled')->default(false)->after('ai_assistant_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $t) {
            $t->dropColumn('support_enabled');
        });
    }
};
