<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Blocked senders (Πυλώνας E, Phase 4 — spam/block-sender). A per-tenant blocklist
 * the inbound router checks BEFORE routing an email into a ticket: a match drops
 * the message (no ticket, no leak). A `pattern` is either a full email
 * (`spammer@x.gr`) or a bare domain (`x.gr`) — both stored lowercased; the router
 * matches the sender's address OR its domain against it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_blocked_senders', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('pattern', 191); // full email OR bare domain, lowercased (191 = form cap + index-safe)
            $t->string('reason')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['company_id', 'pattern']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_blocked_senders');
    }
};
