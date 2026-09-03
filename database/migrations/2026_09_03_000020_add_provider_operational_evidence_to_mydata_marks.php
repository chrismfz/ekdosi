<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PROV-009: persist the provider's operational evidence returned on every issue.
 *
 * InvoSign's issue/status response carries two fields we parsed-and-dropped:
 *   - remaining_invoices : the provider account's REMAINING QUOTA (a shared
 *     counter for the tenant, decremented per filing) — drives a dashboard widget
 *     + a low-quota warning. It arrives free on every filing, so no polling.
 *   - receptionEmails    : the recipient email(s) the provider notified for this
 *     document (empty until the customer-notification path is configured) —
 *     forensic "who did the provider send it to".
 *
 * Nullable + additive: direct-myDATA marks and existing rows leave them null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mydata_marks', function (Blueprint $t) {
            if (! Schema::hasColumn('mydata_marks', 'remaining_invoices')) {
                $t->unsignedInteger('remaining_invoices')->nullable()->after('uid');
            }
            if (! Schema::hasColumn('mydata_marks', 'reception_emails')) {
                // TEXT, not a bounded VARCHAR: the recipient list is provider-controlled
                // and unbounded, and this write shares the transaction that commits
                // mydata_state=VALID — a length overflow here would roll back a filing
                // AADE already accepted (→ a re-file → duplicate). TEXT can't overflow
                // for an email list, mirroring how request/response XML is stored.
                $t->text('reception_emails')->nullable()->after('remaining_invoices');
            }
        });
    }

    public function down(): void
    {
        Schema::table('mydata_marks', function (Blueprint $t) {
            $t->dropColumn(['remaining_invoices', 'reception_emails']);
        });
    }
};
