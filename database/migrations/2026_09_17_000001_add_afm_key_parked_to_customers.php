<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `customers.afm_key_parked` — «this row deliberately does NOT hold the ΑΦΜ
 * identity», set ONLY by the Firebird ETL.
 *
 * The legacy application had no branch field, so a customer's υποκατάστημα was
 * modelled as a SECOND customer row carrying the same ΑΦΜ (the «duplicate-ΑΦΜ
 * branch hack» that `invoices.counterpart_branch` replaces). Those rows must
 * still import — with their documents, balances and ΑΦΜ text intact — but only
 * ONE of them can hold the identity behind UNIQUE(company_id, afm_key). The
 * operator names the holder with `migrate:firebird --afm-keep=CUST_ID`; every
 * other row of that ΑΦΜ imports parked (`afm_key = NULL`, this flag true).
 *
 * Why a flag and not just a NULL key: `Customer::saving()` re-derives `afm_key`
 * from `afm` on every save, so without it the first panel edit of a parked row
 * (fixing a phone number) would re-claim the identity and hit the unique index —
 * the operator could never save the row. The flag is NOT fillable and has no form
 * field: nothing an operator can flip to bypass the ΑΦΜ hardening. It self-clears
 * the moment the ΑΦΜ stops colliding (see Customer::deriveAfmKey).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('customers', 'afm_key_parked')) {
            return;
        }

        Schema::table('customers', function (Blueprint $t): void {
            $t->boolean('afm_key_parked')->default(false)->after('afm_key');
        });
    }

    public function down(): void
    {
        Schema::table('customers', fn (Blueprint $t) => $t->dropColumn('afm_key_parked'));
    }
};
