<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-tenant kill-switch for the whole Domains pillar (Πυλώνας A — A0).
 * Default OFF → the Domains cluster and every screen in it stay hidden until a
 * super-admin turns it on for the tenant (only MyIP uses it). Same shape as
 * support_enabled / ai_assistant_enabled. Design: docs/domains/README.md §1/§8.1.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $t) {
            $t->boolean('enable_domain_management')->default(false)->after('support_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $t) {
            $t->dropColumn('enable_domain_management');
        });
    }
};
