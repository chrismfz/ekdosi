<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Support departments (Πυλώνας E) — the routing unit, WHMCS «Support Departments»
 * parity. Each dept = a name + (Phase 3) its own mailbox (IMAP poll, per-dept
 * host/creds) + toggles (clients-only, autoresponder, feedback-on-close…). The
 * IMAP settings live HERE (encrypted on the model), edited in the Settings
 * Cluster — the shipped system never hardcodes mail config.
 *
 * Plus the `ticket_department_user` pivot = which operators own/watch a dept.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_departments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('name', 120);
            // The support@/sales@/info@ address: detects inbound + sends outbound (Phase 3).
            $t->string('email')->nullable();
            // Per-department mailbox to poll (Phase 3). Password encrypted on the model.
            $t->string('imap_host')->nullable();
            $t->unsignedSmallInteger('imap_port')->default(993);
            $t->string('imap_username')->nullable();
            $t->text('imap_password')->nullable();
            $t->string('imap_encryption', 10)->default('ssl'); // ssl|tls|none
            $t->string('imap_folder')->default('INBOX');
            // Accept a ticket/reply ONLY from a registered Customer (WHMCS «Clients Only»).
            $t->boolean('clients_only')->default(false);
            $t->boolean('autoresponder')->default(true);
            $t->boolean('feedback_on_close')->default(false);
            $t->boolean('prevent_client_closure')->default(false);
            $t->boolean('is_hidden')->default(false); // not offered in the portal picker
            $t->unsignedInteger('sort')->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->softDeletes();
            $t->unique(['company_id', 'name']);
            $t->unique(['company_id', 'email']);
        });

        Schema::create('ticket_department_user', function (Blueprint $t) {
            $t->foreignId('ticket_department_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->primary(['ticket_department_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_department_user');
        Schema::dropIfExists('ticket_departments');
    }
};
