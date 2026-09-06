<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-poll health log for the IMAP mailbox poller (Πυλώνας E, Phase 3b): one row
 * per department per run — connected?, fetched, processed, errors. The
 * observability the operator/MCP reads to answer «does IMAP work?» without grepping
 * the log. Runtime data — excluded from the portability bundle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_poll_runs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('ticket_department_id')->nullable()->constrained()->nullOnDelete();
            $t->boolean('connected')->default(false);
            $t->unsignedInteger('fetched')->default(0);
            $t->unsignedInteger('processed')->default(0);
            $t->json('errors')->nullable();
            $t->timestamps();
            $t->index(['company_id', 'created_at']);
            $t->index(['ticket_department_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_poll_runs');
    }
};
