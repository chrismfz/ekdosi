<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-connection default myDATA «Τρόπος πληρωμής». A gateway settlement wrote a
 * Payment with NO payment_method, so the «Τρόπος» column read «—» and the myDATA
 * payment-type was unset. Now the operator maps each online channel → a myDATA
 * PaymentMethod (e.g. Eurobank/κάρτα → «Ηλεκτρονικά μέσα Πληρωμών»), and settle()
 * stamps it automatically. Null = leave the Payment method blank (as before).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_gateway_connections', function (Blueprint $t): void {
            $t->foreignId('payment_method_id')
                ->nullable()
                ->after('gateway')
                ->constrained('payment_methods')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payment_gateway_connections', function (Blueprint $t): void {
            $t->dropConstrainedForeignId('payment_method_id');
        });
    }
};
