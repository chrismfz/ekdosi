<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P1 of the e-invoice-provider work (docs/paroxos/implementation-plan.md §3):
 * per-tenant provider selection + credentials, ALONGSIDE the existing myDATA
 * credentials (which stay as the read/reconciliation path — NOT touched here).
 *
 *   - einvoice_provider_key    : which provider (e.g. 'invosign' / 'sbz'),
 *                                resolved by ProviderTransportRegistry.
 *   - einvoice_provider_config : encrypted JSON credential blob (api key / token /
 *                                endpoint / provider AFM + ΥΠΑΗΕΣ licence no.).
 *                                Encrypted via the Company `encrypted:array` cast
 *                                — same pattern as mydata_subscription_key_*.
 *   - einvoice_provider_mode   : 'off' | 'sandbox' | 'production' — the twin of
 *                                mydata_mode, so a provider can be tested without
 *                                touching the tenant's myDATA filing.
 *
 * No behaviour change: every tenant keeps einvoice_provider='gr-mydata' (the
 * factory still routes to MyDataSubmitter); these columns are inert until a
 * tenant is moved to 'gr-provider' (P2+).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $t) {
            $t->string('einvoice_provider_key', 40)->nullable()->after('einvoice_provider');
            $t->text('einvoice_provider_config')->nullable()->after('einvoice_provider_key'); // encrypted:array cast
            $t->string('einvoice_provider_mode', 16)->default('off')->after('einvoice_provider_config');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $t) {
            $t->dropColumn([
                'einvoice_provider_key',
                'einvoice_provider_config',
                'einvoice_provider_mode',
            ]);
        });
    }
};
