<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «Log πύλης»: keep the vPOS return diagnostics WITH the event row.
 *
 * The gateway already works out why a return was refused (an unlisted field, a
 * field order that disagrees with ours, a digest that matches under neither) —
 * but that reasoning only reached `laravel.log`, so diagnosing a payment problem
 * meant SSH. An operator has the «Log πύλης» screen and nothing else; this column
 * is what lets that screen answer the question on its own.
 *
 * JSON, nullable: only the gateway paths that HAVE something to say fill it, and
 * it holds field NAMES only — never values, which carry the customer's order data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_gateway_events', function (Blueprint $table): void {
            $table->json('diagnostics')->nullable()->after('message');
        });
    }

    public function down(): void
    {
        Schema::table('payment_gateway_events', function (Blueprint $table): void {
            $table->dropColumn('diagnostics');
        });
    }
};
