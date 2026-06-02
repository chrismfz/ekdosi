<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drop the inert legacy paper-print / mail flags from `invoices`.
 *
 * `mailed`, `printed`, `email_sent` were imported verbatim from the legacy
 * Firebird INVOICE table (MAILED / PRINTED / EMAIL_SENT) but the new app never
 * maintains them: email tracking is the mail-log (`invoice_mail_logs` →
 * `latestMailLog`, the «Email» badge + «Send history» tab), and there is no
 * paper-print workflow (PDF download instead). Shown in the View/list they read
 * as live state but are frozen-at-import relics — confusing — so we remove them.
 *
 * The ETL (`MigrateFromFirebird`) no longer maps these columns, so a re-import
 * simply ignores the legacy fields (no error). The legacy Firebird DB retains
 * the original values as the archive if ever needed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $t) {
            $t->dropColumn(['mailed', 'printed', 'email_sent']);
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $t) {
            $t->boolean('mailed')->default(false);
            $t->boolean('printed')->default(false);
            $t->string('email_sent', 120)->nullable();
        });
    }
};
