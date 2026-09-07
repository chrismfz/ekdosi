<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ticket watchers / CC (Πυλώνας E, Phase 4). A watcher is someone who wants every
 * public update on a ticket:
 *   - an OPERATOR (`user_id`) → gets the in-panel «bell» when the customer posts;
 *   - a plain EMAIL (`email`) → is Cc'd on the operator's outbound replies.
 * Exactly one of `user_id` / `email` is set. `source` records how the watch came to
 * be (manual add, or an operator who replied = participant) for the future
 * inbound-CC auto-capture. Deleting the ticket cascades the watchers away.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_watchers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete(); // operator watcher
            $t->string('email')->nullable(); // external CC watcher (lowercased)
            $t->string('source', 12)->default('manual'); // manual|participant|cc
            $t->timestamps();
            // One row per (ticket, operator) and per (ticket, email); the partial
            // NULLs never collide (a user row has null email and vice-versa).
            $t->unique(['ticket_id', 'user_id']);
            $t->unique(['ticket_id', 'email']);
            $t->index(['company_id', 'ticket_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_watchers');
    }
};
