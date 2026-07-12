<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-company opt-in for the AUTOMATIC (scheduled) myDATA expenses refresh.
 *
 *   companies.mydata_auto_fetch_expenses  (default false)
 *
 * The `mydata:refresh-expenses` cron is a SINGLE deploy-wide switch
 * (super_admin, «Χρονοπρογραμματιστής»). This per-tenant flag is the second
 * key (two-key, like WHMCS auto-issue): when the scheduled task runs it
 * refreshes ONLY the tenants that opted in here — so a company_admin controls
 * their OWN auto-refresh from «Ρυθμίσεις εταιρείας» without touching others.
 * READ-ONLY task (refreshes the «αδέσποτα έξοδα» worklist; creates no rows).
 * Default OFF so nothing changes until a tenant opts in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->boolean('mydata_auto_fetch_expenses')
                ->default(false)
                ->after('auto_email_on_issue');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropColumn('mydata_auto_fetch_expenses');
        });
    }
};
