<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Domains pillar (Πυλώνας A / A3) — docs/domains/README.md §9 «API history».
 *
 * One row per registrar WRITE command (renew/register/transfer/…): what we
 * asked, what came back, whether it stuck. Lands BEFORE the first write
 * lands (A3a) — a state-changing call against a production registrar account
 * is never fired unlogged. Reads (sync/availability) are deliberately NOT
 * logged here (volume — the nightly sync touches every domain; its outcome
 * already lives on the row in last_synced_at/sync_error).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('domain_registrar_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('domain_id')->nullable()->constrained('domains')->nullOnDelete();
            $t->foreignId('registrar_connection_id')->nullable()
                ->constrained('domain_registrar_connections')->nullOnDelete();
            $t->string('action', 40);                 // renew | register | transfer | …
            $t->string('status', 20);                 // ok | adopted | failed
            $t->json('request')->nullable();          // sanitized params (NEVER credentials)
            $t->json('response')->nullable();         // sanitized result snapshot
            $t->text('error')->nullable();
            $t->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete(); // the issuing invoice (on-issue renewals)
            $t->timestamps();

            $t->index(['company_id', 'domain_id']);
            $t->index(['company_id', 'action', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domain_registrar_logs');
    }
};
