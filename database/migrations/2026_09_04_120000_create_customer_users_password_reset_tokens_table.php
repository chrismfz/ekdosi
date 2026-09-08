<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A DEDICATED password-reset token table for the customer portal, separate from
 * the operator `password_reset_tokens`. Both tables are keyed by email alone, so
 * a shared table would collide when the same address exists as BOTH an operator
 * (`users`) and a customer (`customer_users`) — a portal reset would overwrite
 * or consume the operator's token (and vice-versa), letting a token minted for
 * one guard be redeemed against the other. Separate tables keep the two brokers
 * fully independent. Wired via `config/auth.php` → `passwords.customer_users`.
 * (The portal reset flow itself ships in a later slice; the table is the safe
 * foundation it will use.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_users_password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_users_password_reset_tokens');
    }
};
