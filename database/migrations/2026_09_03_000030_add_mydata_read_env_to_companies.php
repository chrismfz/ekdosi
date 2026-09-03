<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Explicit myDATA READ-environment override, decoupling the read env from the
 * submit channel/mode.
 *
 * Until now the environment we READ from (RequestTransmittedDocs / RequestDocs /
 * reconciliation / consoles) always followed the SUBMIT mode: a `gr-mydata`
 * tenant read its `mydata_mode`, a `gr-provider` tenant read its
 * `einvoice_provider_mode` (Company::mydataReadMode). That is the right default,
 * but it makes one legitimate case impossible: a tenant testing a provider in
 * SANDBOX (InvoSign Δοκιμαστικό) that wants to READ its real PRODUCTION myDATA
 * picture — it only ever saw the handful of synthetic sandbox documents.
 *
 * `mydata_read_env` (null | 'sandbox' | 'production') is that override. NULL —
 * the default for every existing tenant — means "follow the submit mode" (the
 * exact current behaviour, so this migration changes nothing until an operator
 * sets it). A value is honoured only when the chosen environment has read
 * credentials; reads never write to AADE, so preferring the operator's explicit
 * choice is safe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $t) {
            // null = auto (follow submit mode); 'sandbox' | 'production' = override.
            $t->string('mydata_read_env', 16)->nullable()->after('mydata_mode');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $t) {
            $t->dropColumn('mydata_read_env');
        });
    }
};
