<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «Αναφορές Ταμείου»: who rang each till document (a session can have more than
 * one cashier — its opener/closer don't say who sold what). Set by the till
 * actions from the signed-in user; null on non-till documents.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('pos_cashier_id')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pos_cashier_id');
        });
    }
};
