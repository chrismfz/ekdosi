<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Split the single myDATA REST credential pair into TWO independent
 * per-environment pairs: one for the AADE sandbox/developer endpoint,
 * one for production/live.
 *
 * Why: `mydata_mode` already selects the environment (off / sandbox /
 * production), but the credentials were stored once — so flipping a
 * tenant from sandbox to production (and back, for testing) meant
 * re-keying the aade-user-id + subscription-key every single time.
 * With separate slots the operator stores each set ONCE and just
 * switches `mydata_mode` to pick the environment.
 *
 * The subscription key columns are encrypted via the Company cast.
 * Copying the RAW stored ciphertext between columns preserves the
 * value (same APP_KEY, same `encrypted` cast on both ends) — no
 * decrypt/re-encrypt round-trip is needed.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1) Add the per-environment credential slots.
        Schema::table('companies', function (Blueprint $t) {
            $t->string('mydata_aade_id_sandbox')->nullable()->after('mydata_subscription_key');
            $t->text('mydata_subscription_key_sandbox')->nullable()->after('mydata_aade_id_sandbox');
            $t->string('mydata_aade_id_production')->nullable()->after('mydata_subscription_key_sandbox');
            $t->text('mydata_subscription_key_production')->nullable()->after('mydata_aade_id_production');
        });

        // 2) Migrate the existing single pair into the slot matching each
        //    tenant's current mode. Production-mode tenants keep their creds
        //    as production; everyone else (sandbox / off) lands in sandbox —
        //    the safe default, since off has no endpoint of its own.
        DB::table('companies')
            ->where('mydata_mode', 'production')
            ->update([
                'mydata_aade_id_production' => DB::raw('mydata_aade_id'),
                'mydata_subscription_key_production' => DB::raw('mydata_subscription_key'),
            ]);

        DB::table('companies')
            ->where('mydata_mode', '!=', 'production')
            ->update([
                'mydata_aade_id_sandbox' => DB::raw('mydata_aade_id'),
                'mydata_subscription_key_sandbox' => DB::raw('mydata_subscription_key'),
            ]);

        // 3) Drop the now-redundant single pair.
        Schema::table('companies', function (Blueprint $t) {
            $t->dropColumn(['mydata_aade_id', 'mydata_subscription_key']);
        });
    }

    public function down(): void
    {
        // Re-add the single pair…
        Schema::table('companies', function (Blueprint $t) {
            $t->string('mydata_aade_id')->nullable()->after('email');
            $t->text('mydata_subscription_key')->nullable()->after('mydata_aade_id');
        });

        // …and restore the active-mode credentials into it.
        DB::table('companies')
            ->where('mydata_mode', 'production')
            ->update([
                'mydata_aade_id' => DB::raw('mydata_aade_id_production'),
                'mydata_subscription_key' => DB::raw('mydata_subscription_key_production'),
            ]);

        DB::table('companies')
            ->where('mydata_mode', '!=', 'production')
            ->update([
                'mydata_aade_id' => DB::raw('mydata_aade_id_sandbox'),
                'mydata_subscription_key' => DB::raw('mydata_subscription_key_sandbox'),
            ]);

        Schema::table('companies', function (Blueprint $t) {
            $t->dropColumn([
                'mydata_aade_id_sandbox',
                'mydata_subscription_key_sandbox',
                'mydata_aade_id_production',
                'mydata_subscription_key_production',
            ]);
        });
    }
};
