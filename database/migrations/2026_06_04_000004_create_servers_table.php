<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A single provisioning target (one cPanel/Mailcow/DirectAdmin/license/antivirus
 * host) that ekdosi can eventually talk to DIRECTLY with its own credentials —
 * no WHMCS in between. Per-server creds override the group's (WHMCS server-group
 * fallback pattern, but native). `secret_encrypted` = Laravel `encrypted` cast.
 * Schema-only placeholder: simple CRUD lookup now, zero live API calls.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('servers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('server_group_id')->nullable()->constrained()->nullOnDelete();
            $t->string('name', 120);
            $t->string('module', 40)->nullable();      // overrides the group's module
            $t->string('hostname', 190)->nullable();
            $t->string('api_endpoint', 255)->nullable();
            $t->string('username', 190)->nullable();
            $t->text('secret_encrypted')->nullable();  // Laravel `encrypted` cast on the model
            $t->json('meta')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('servers');
    }
};
