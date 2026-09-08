<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The batch mail sweep (`invoices:resend-failed-emails`) dispatches
 * SendInvoiceEmail with trigger='batch', but the invoice_mail_log.trigger
 * enum only allowed ['auto','manual'] — so the job's log-row insert threw an
 * enum/CHECK violation on every batch send (never caught: the command's test
 * fakes the queue, so handle() never ran). Widen the enum to include 'batch'.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_mail_log', function (Blueprint $table) {
            $table->enum('trigger', ['auto', 'manual', 'batch'])->default('auto')->change();
        });
    }

    public function down(): void
    {
        Schema::table('invoice_mail_log', function (Blueprint $table) {
            $table->enum('trigger', ['auto', 'manual'])->default('auto')->change();
        });
    }
};
