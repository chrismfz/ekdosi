<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer-portal login identity (Slice 0 of the customer portal).
 *
 * DELIBERATELY LEAN — auth + account-safety only. NO business/legal data lives
 * here: ΑΦΜ/address/occupation stay on `customers`, and which customer(s) a login
 * may see is a SEPARATE grant table (`customer_user_access`, a later slice). The
 * login identity is GLOBAL (email unique across all tenants) so one person with
 * the same email in several companies has ONE login + many grants.
 *
 * A few forward-looking columns are provisioned now but sit DORMANT (all
 * nullable → ~zero cost) so we never have to re-migrate this table for them:
 *   - username/locale/phone — optional profile/login knobs,
 *   - two_factor_* — TOTP, named EXACTLY as Laravel Fortify expects (adopting it
 *     later is then friction-free). Passkeys/WebAuthn are multi-valued and will
 *     get their OWN table when needed, never columns here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            // The global login identity (store normalised/lowercased).
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            // Nullable ON PURPOSE: an invited/backfilled login exists before it
            // has a password (set on claim/invite). A null password can never
            // authenticate (Hash::check fails), so an unclaimed row is inert.
            $table->string('password')->nullable();
            $table->rememberToken();
            // Lifecycle, richer than a bool: 'invited' (created, not yet claimed),
            // 'active' (may log in), 'suspended' (blocked). Only 'active' logs in.
            $table->string('status')->default('invited')->index();
            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();   // IPv6-safe

            // ── Dormant / standby (provisioned now, wired later) ──────────────
            $table->string('username')->nullable()->unique();
            $table->string('locale', 12)->nullable();
            $table->string('phone', 40)->nullable();
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->timestamp('password_changed_at')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_users');
    }
};
