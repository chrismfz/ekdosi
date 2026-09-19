<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional per-tenant custom portal host (multi-domain #1c, Option A "soft").
 * Nullable: null = the tenant is reachable only via the shared default host. Set
 * (e.g. `cs.nixpal.com`) = the customer portal ALSO answers on that host, and a
 * host→company resolver (ResolvePortalHost) pins the tenant so the GUEST pages
 * (login/reset) render in that tenant's default language + show its branding.
 * Data access stays per-grant (cross-tenant) — the host is a language/branding
 * hint, not a security boundary. Unique so a host maps to at most one tenant;
 * MariaDB/sqlite both allow multiple NULLs under a UNIQUE index.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->string('portal_host')->nullable()->unique()->after('slug');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropUnique(['portal_host']);
            $table->dropColumn('portal_host');
        });
    }
};
