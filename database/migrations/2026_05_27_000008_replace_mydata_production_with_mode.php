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
 * Migration policy (preserves the SAFER default):
 *   - mydata_production = true  AND einvoice_provider = 'gr-mydata'
 *                                → mode = 'production'
 *   - everything else            → mode = 'off' (the safe default)
 *
 * Why NOT map false → 'sandbox' (the obvious thing)?  The boolean
 * meant "are you sending real submissions to AADE production?".
 * False meant "no" — which could be ANY of: testing in sandbox,
 * deliberately not submitting, or just-created-haven't-configured-yet.
 * Mapping all of those to 'sandbox' would push Estonian (ee-peppol)
 * tenants and PDF-only tenants to start hitting AADE sandbox the
 * moment PR #25's real submitter lands. Defaulting to 'off' preserves
 * the operator's previous intent: "don't submit unless I explicitly
 * opt in to a mode."
 *
 * Operators who WERE testing in sandbox under the old boolean can
 * re-select 'sandbox' from the new 3-way Select after the migration
 * runs. The cost of a one-time mode reselect (3 tenants in current
 * scope) is much smaller than the cost of silently opting tenants
 * into AADE sandbox calls.
 *
 * Round-trip note: down() then up() can lose 'sandbox' state on
 * tenants that ended up there manually after the original up() ran.
 * That's acceptable — any direction of information loss is preferable
 * to silently flipping 'off' → 'sandbox' on a re-migration cycle
 * (which would inadvertently opt tenants into AADE traffic).
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

        // Only flip tenants that were demonstrably in production
        // (mydata_production = 1 AND einvoice_provider = 'gr-mydata').
        // Everyone else stays at the column default 'off'.
        DB::table('companies')
            ->where('mydata_production', 1)
            ->where('einvoice_provider', 'gr-mydata')
            ->update(['mydata_mode' => 'production']);

        Schema::table('companies', function (Blueprint $t) {
            $t->dropColumn('mydata_production');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $t) {
            $t->boolean('mydata_production')->default(false)->after('mydata_subscription_key');
        });

        // Only 'production' becomes true. Sandbox and Off both → false,
        // matching the boolean's pre-migration semantics.
        DB::table('companies')
            ->where('mydata_mode', 'production')
            ->update(['mydata_production' => 1]);

        Schema::table('companies', function (Blueprint $t) {
            $t->dropColumn('mydata_mode');
        });
    }
};
