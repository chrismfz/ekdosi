<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B1 (first hosted gateway — Eurobank vPOS): remember WHICH connection started an
 * intent. The manual/offline flow never needed it (the operator settles), but an
 * online return webhook MUST resolve the intent → its connection → the shared
 * secret to verify the provider digest. Nullable (manual intents leave it null)
 * and nullOnDelete so removing a method never orphans-cascade its history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_intents', function (Blueprint $t): void {
            $t->foreignId('payment_gateway_connection_id')
                ->nullable()
                ->after('gateway')
                ->constrained('payment_gateway_connections')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payment_intents', function (Blueprint $t): void {
            $t->dropConstrainedForeignId('payment_gateway_connection_id');
        });
    }
};
