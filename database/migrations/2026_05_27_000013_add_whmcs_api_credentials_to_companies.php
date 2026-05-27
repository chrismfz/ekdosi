<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PR #28 (WHMCS bridge — Stage A): per-tenant WHMCS API credentials.
 *
 * Stage A is read-only: we connect, list paid+unfiled invoices, match
 * each against customers.whmcs_client_id, and print a dry-run preview.
 * No issuance, no write-back to WHMCS — that's Stage B (PR #29).
 *
 * Auth: identifier + secret (WHMCS's modern API credential mechanism,
 * created in Setup → Staff Management → API Credentials). The legacy
 * password-in-Registry approach (FDBParams.cpp:80-86) is NOT carried
 * over — it stored full account passwords in plaintext under
 * HKCU\Software\... Per CLAUDE.md, we go API-only.
 *
 * whmcs_custom_field_map: the legacy WHMCS instance has fixed custom
 * field IDs (12=toinvoice, 13=vatno, 14=taxoffice, 15=occupation,
 * 338=griniaris — verified at legacy/ekdosi-main/FAutoInvoice.cpp).
 * Other tenants' WHMCS instances will have different IDs — same
 * concept, different numbers. Storing the mapping per-tenant as JSON
 * means each tenant tells us "in MY WHMCS, fieldid=42 is the AFM".
 * No hardcoding leaks into our code.
 *
 * Empty whmcs_api_url disables the integration entirely (the
 * scheduled pull command + Filament UI hide the WHMCS bits). Default
 * is null — opt-in per tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $t): void {
            // The WHMCS install's API endpoint — the path that ends
            // in /includes/api.php, e.g. https://billing.myip.gr/includes/api.php.
            // We store the FULL URL (not host + path components)
            // because WHMCS installs can be on subpaths
            // (/billing/whmcs/includes/api.php) and a host-only
            // column would force us to guess.
            $t->string('whmcs_api_url', 500)->nullable()->after('mail_body_template');

            // Identifier + Secret pair, generated in WHMCS at
            // Setup → Staff Mgmt → API Credentials. The identifier
            // is the username-equivalent; the secret is what we
            // present.
            $t->string('whmcs_api_identifier', 191)->nullable()->after('whmcs_api_url');
            $t->text('whmcs_api_secret')->nullable()->after('whmcs_api_identifier');

            // Per-tenant role → WHMCS-custom-field-id mapping.
            // Shape: {"vatno": 13, "taxoffice": 14, "occupation": 15,
            //         "griniaris": 338, "toinvoice": 12}
            // Keys are our canonical role names; values are WHMCS
            // custom field IDs. Lookup direction is role → fieldid
            // (we know what we want, we ask the tenant where it lives).
            $t->json('whmcs_custom_field_map')->nullable()->after('whmcs_api_secret');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $t): void {
            $t->dropColumn([
                'whmcs_api_url',
                'whmcs_api_identifier',
                'whmcs_api_secret',
                'whmcs_custom_field_map',
            ]);
        });
    }
};
