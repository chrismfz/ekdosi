<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-account «show on invoices» flag. The invoice PDF now lists ALL the tenant's
 * payment accounts (like a typical Greek τιμολόγιο with several IBANs), so the
 * customer can pay via any — but an operator may want to hide some (e.g. a
 * payroll account). Default true = existing accounts all print.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_accounts', function (Blueprint $t) {
            $t->boolean('show_on_invoices')->default(true)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('bank_accounts', function (Blueprint $t) {
            $t->dropColumn('show_on_invoices');
        });
    }
};
