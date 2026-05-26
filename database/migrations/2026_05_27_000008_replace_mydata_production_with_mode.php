<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Replace `companies.mydata_production` (boolean) with
 * `companies.mydata_mode` (varchar, 'off' | 'sandbox' | 'production').
 *
 * The boolean couldn't express "PDF only, no AADE submission at all" —
 * a legitimate state for:
 *   - tenants in transition before going live
 *   - training tenants exercising the WHMCS bridge without filing
 *   - emergency mode when AADE is down for maintenance
 *   - the dev sandbox testing of PDF / templates without burning AADE
 *     quota
 *
 * Migration policy:
 *   - mydata_production = true   → mode = 'production'
 *   - mydata_production = false  → mode = 'sandbox'   (operator was
 *                                  testing against the AADE dev
 *                                  endpoint per the legacy boolean's
 *                                  semantics)
 *   - tenants with neither (fresh installs) → 'off' via default
 *
 * The varchar (not native MySQL enum) so adding modes later doesn't
 * require an ALTER TABLE; validation lives in App\Enums\MyDataMode.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $t) {
            $t->string('mydata_mode', 16)->default('off')->after('mydata_subscription_key');
        });

        DB::table('companies')->update([
            'mydata_mode' => DB::raw("CASE WHEN mydata_production = 1 THEN 'production' ELSE 'sandbox' END"),
        ]);

        Schema::table('companies', function (Blueprint $t) {
            $t->dropColumn('mydata_production');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $t) {
            $t->boolean('mydata_production')->default(false)->after('mydata_subscription_key');
        });

        DB::table('companies')->update([
            'mydata_production' => DB::raw("CASE WHEN mydata_mode = 'production' THEN 1 ELSE 0 END"),
        ]);

        Schema::table('companies', function (Blueprint $t) {
            $t->dropColumn('mydata_mode');
        });
    }
};
