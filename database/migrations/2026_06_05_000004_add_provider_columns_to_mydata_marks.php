<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P1 of the e-invoice-provider work: provider-side audit columns on the existing
 * mydata_marks table (the legal source of truth — kept, not duplicated). A future
 * GrProviderSubmitter (P2) fills these alongside the usual mark/request/response;
 * the direct MyDataSubmitter leaves them null.
 *
 *   - provider_key         : which provider produced this mark (e.g. 'invosign').
 *   - authentication_code  : the provider's αυθεντικοποίηση / seal string (AADE
 *                            authenticationCode) returned on a provider filing.
 *   - delivery_state       : provider-reported delivery status (provider-specific).
 *
 * Nullable + unwritten in P1 → pure additive, no behaviour change. mydata_action
 * will additionally carry PROVIDER_INSERT / PROVIDER_CANCEL when P2 lands (a value
 * widening, no schema change — the column is already a string(30)).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mydata_marks', function (Blueprint $t) {
            $t->string('provider_key', 40)->nullable()->after('mydata_action');
            $t->string('authentication_code', 255)->nullable()->after('provider_key');
            $t->string('delivery_state', 40)->nullable()->after('authentication_code');
        });
    }

    public function down(): void
    {
        Schema::table('mydata_marks', function (Blueprint $t) {
            $t->dropColumn(['provider_key', 'authentication_code', 'delivery_state']);
        });
    }
};
