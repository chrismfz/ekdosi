<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «Ιστορικό ενεργειών ταμία»: what happens at the till that never becomes a receipt
 * (a cart emptied, an item taken off after scanning, a typed open price, a discount,
 * a reprint, a failed issue, a return opened/cancelled, an X report) — for the
 * owner's «Αναφορές ταμείου». Append-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pos_session_id')->nullable()->constrained('pos_sessions')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 32);
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->decimal('qty', 9, 3)->nullable();
            $table->decimal('amount', 14, 2)->nullable();   // the money it concerns (VAT incl.)
            $table->string('note')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'created_at']);
            $table->index(['pos_session_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_events');
    }
};
