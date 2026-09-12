<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Auth/security audit log — every login, logout, failed attempt and lockout on
 * BOTH panels (web = /admin operators, portal = /user customers). System-level
 * and cross-tenant on purpose: a failed login for a NON-EXISTENT username has no
 * user and no tenant, and brute-force/recon is a whole-surface concern. So there
 * is NO company_id here (super-admin-only visibility). Written best-effort from
 * an Auth-event subscriber; never blocks authentication.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auth_events', function (Blueprint $table) {
            $table->id();

            // 'web' (/admin, App\Models\User) or 'portal' (/user, App\Models\CustomerUser).
            $table->string('guard', 20);

            // login | logout | failed | lockout
            $table->string('event', 20);

            // The account, WHEN known. Null for a failed attempt on an unknown
            // username. Deliberately NOT a FK: the id points at users OR
            // customer_users depending on `guard`, and the row must survive the
            // account being deleted (it's an audit trail).
            $table->unsignedBigInteger('user_id')->nullable();

            // The identifier that was TRIED (or the account's email). This is the
            // recon/brute-force signal — it may be an attacker-supplied string for
            // a non-existent account. The password is NEVER stored.
            $table->string('email')->nullable();

            $table->string('ip_address', 45)->nullable();  // IPv6-safe
            $table->text('user_agent')->nullable();

            // Only ever created (append-only) — no updated_at.
            $table->timestamp('created_at')->nullable()->index();

            // Query paths: recent feed (created_at), "attempts from this IP",
            // "attempts on this identifier", filter by kind.
            $table->index(['ip_address', 'created_at']);
            $table->index(['email', 'created_at']);
            $table->index('event');
            $table->index(['guard', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auth_events');
    }
};
