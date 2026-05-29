<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * G6 — auto-email controls for the non-myDATA issue path + a per-customer
 * opt-out.
 *
 *   companies.auto_email_on_issue  (default false)
 *     Global, per-tenant toggle that mirrors auto_email_on_mydata_accept
 *     but for the NON-myDATA issue path (finalizing a draft on a tenant
 *     that doesn't file via myDATA — provider 'none' / Estonian / mode
 *     off). Default OFF so existing tenants and testing/training tenants
 *     keep today's behaviour (no mail) until an operator opts in.
 *
 *   customers.auto_email_invoices  (default true)
 *     Per-customer opt-out. Gates BOTH automatic paths (myDATA-VALID and
 *     finalize); the manual "Resend email" action ignores it. Default
 *     TRUE = preserve today's behaviour (a customer with an email gets
 *     auto-mailed unless explicitly turned off in their tab).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->boolean('auto_email_on_issue')
                ->default(false)
                ->after('auto_email_on_mydata_accept');
        });

        Schema::table('customers', function (Blueprint $table): void {
            $table->boolean('auto_email_invoices')
                ->default(true)
                ->after('secondary_email');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropColumn('auto_email_on_issue');
        });

        Schema::table('customers', function (Blueprint $table): void {
            $table->dropColumn('auto_email_invoices');
        });
    }
};
