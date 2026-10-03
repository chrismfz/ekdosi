<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «Ταμείο ημέρας» (POS PR 2b): a till session is opened with a cash float, takes
 * cash in/out movements, and is closed with a cash count — an INTERNAL report per
 * payment method (not a legal Ζ). Every till document carries the session it was
 * rung in (`invoices.pos_session_id`), so the report is exact, not a time window.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('opened_at');
            $table->decimal('opening_float', 14, 2)->default(0);
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->decimal('expected_cash', 14, 2)->nullable();
            $table->decimal('counted_cash', 14, 2)->nullable();
            // The report as it stood at closing — frozen (a later cancellation never rewrites a closed day).
            $table->json('closing_report')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'closed_at']);
        });

        Schema::create('pos_cash_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pos_session_id')->constrained('pos_sessions')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('direction', 3);   // in | out
            $table->decimal('amount', 14, 2);  // positive magnitude — direction carries the sign
            $table->string('reason');
            $table->timestamps();
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('pos_session_id')->nullable()->constrained('pos_sessions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pos_session_id');
        });
        Schema::dropIfExists('pos_cash_movements');
        Schema::dropIfExists('pos_sessions');
    }
};
