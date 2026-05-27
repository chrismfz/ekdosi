<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PR #31 (WHMCS bridge - Stage B-1): per-tenant HMAC secret for the
 * /webhooks/whmcs/{slug}/invoice-paid endpoint.
 *
 * Kept SEPARATE from whmcs_api_secret deliberately. The API secret
 * is what we present to WHMCS (outbound auth); the webhook secret is
 * what WHMCS presents to us (inbound auth). A leak of one must not
 * compromise the other. Concretely: WHMCS-side logs that capture
 * outbound webhook bodies could leak the webhook secret; an attacker
 * with that secret can forge inbound calls but cannot impersonate
 * the API caller.
 *
 * Encrypted via the Company model's `whmcs_webhook_secret` cast. The
 * cleartext is rotated by overwriting the form field; old value is
 * unrecoverable, which is intended.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $t): void {
            $t->text('whmcs_webhook_secret')->nullable()->after('whmcs_custom_field_map');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $t): void {
            $t->dropColumn('whmcs_webhook_secret');
        });
    }
};
