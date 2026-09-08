<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tickets + their message thread (Πυλώνας E). A ticket belongs to a department
 * and (when the sender matches) a Customer — null customer = GUEST, with the
 * raw `requester_email` always kept. `reference` = TK-YYYY-MM-DD-xxxxxx (open
 * date + unguessable tail; it is the email subject token). Attachments + tags
 * reuse the existing polymorphic morphs (no ticket_attachments/tags tables).
 *
 * A ticket_message is a public reply OR an operator-only internal note
 * (`is_internal_note`); `author_role` + `author_id` say who wrote it
 * (customer/operator/system) without a morph.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('reference', 40);
            $t->foreignId('ticket_department_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('customer_id')->nullable()->constrained()->nullOnDelete(); // null = GUEST
            $t->string('requester_email')->nullable();
            $t->string('requester_name')->nullable();
            $t->string('subject');
            $t->string('status', 20)->default('open');
            $t->string('priority', 10)->default('normal');
            $t->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $t->string('opened_via', 12)->default('operator'); // portal|email|operator
            $t->timestamp('last_reply_at')->nullable();
            $t->string('last_reply_role', 12)->nullable(); // customer|operator
            $t->timestamp('closed_at')->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->unique(['company_id', 'reference']);
            $t->index(['company_id', 'status']);
            $t->index(['company_id', 'ticket_department_id']);
            $t->index(['company_id', 'customer_id']);
            $t->index(['company_id', 'assigned_to']);
        });

        Schema::create('ticket_messages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $t->string('author_role', 12); // customer|operator|system
            $t->unsignedBigInteger('author_id')->nullable(); // customers.id or users.id per role
            $t->text('body'); // cleaned (quotes/signature stripped on the email path)
            $t->text('body_original')->nullable(); // raw with quotes — audit only
            $t->boolean('is_internal_note')->default(false); // operator-only, never emailed/shown
            $t->string('via', 12)->default('operator'); // portal|email|operator|system
            $t->string('email_message_id')->nullable(); // RFC Message-ID for threading (Phase 3)
            $t->timestamps();
            $t->index(['ticket_id', 'id']);
            $t->index('email_message_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_messages');
        Schema::dropIfExists('tickets');
    }
};
