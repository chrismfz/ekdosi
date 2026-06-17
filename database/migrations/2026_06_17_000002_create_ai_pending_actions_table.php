<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI «Βοηθός» Phase 2b — the operator-confirm staging queue for WRITE actions.
 *
 * The assistant NEVER executes a side effect (send an email, set a reminder)
 * directly: a write tool stages a `pending` row here with the resolved, already
 * validated target, and the operator confirms/cancels it with a button. The
 * executor re-validates from THIS row (never client input) and flips the status
 * — so a confirmed action is auditable and a write can never auto-fire.
 *
 * Doubles as the reminder store: a confirmed `reminder` row is the live
 * reminder; `ai:dispatch-reminders` delivers it (Filament DB notification) once
 * `remind_at` is due.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_pending_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('conversation_id')->nullable();

            // 'send_statement' | 'reminder'
            $table->string('type', 32);
            // 'pending' | 'confirmed' | 'cancelled' | 'failed'
            $table->string('status', 16)->default('pending');

            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();

            // Greek human description shown on the confirm card.
            $table->string('summary', 500);
            // Resolved, validated specifics (recipients / note / etc.).
            $table->json('payload')->nullable();

            // Reminder due time (null for non-reminder types).
            $table->timestamp('remind_at')->nullable();

            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('delivered_at')->nullable();

            // Outcome text after execution (sent-to / error).
            $table->string('result', 500)->nullable();

            $table->timestamps();

            // The confirm UI queries pending rows per tenant+user.
            $table->index(['company_id', 'user_id', 'status']);
            // The reminder sweeper queries due, confirmed, undelivered reminders.
            $table->index(['type', 'status', 'remind_at', 'delivered_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_pending_actions');
    }
};
