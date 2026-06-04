<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Server groups — a reseller-style bundle of provisioning targets sharing
 * credentials (e.g. all cPanel boxes under one WHM reseller account, all Mailcow
 * hosts under one API key). NATIVE & WHMCS-INDEPENDENT: these are OUR servers,
 * reached by OUR credentials, not via WHMCS. `secret_encrypted` uses Laravel's
 * `encrypted` cast (never plaintext — same as the myDATA/GSIS creds on
 * companies). Schema-only placeholder: created empty, no API calls in this phase.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_groups', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('name', 120);
            $t->string('module', 40)->nullable();      // default provisioning module for the group
            $t->string('username', 190)->nullable();
            $t->text('secret_encrypted')->nullable();  // Laravel `encrypted` cast on the model
            $t->json('meta')->nullable();
            $t->text('notes')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_groups');
    }
};
