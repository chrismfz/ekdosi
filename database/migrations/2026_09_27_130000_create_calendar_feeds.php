<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «Το ημερολόγιό μου» (ICS) — one read-only subscription link per user per
 * company, for Thunderbird / Google / phone calendars (they can't log in). The
 * link carries a random token: looked up by its SHA-256, kept encrypted only so
 * the owner can see their link again. Rotate = new token; revoke = row deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_feeds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->text('token');                                   // encrypted cast
            $table->boolean('include_leads')->default(true);
            $table->boolean('include_leaves')->default(true);
            $table->boolean('include_team')->default(true);
            $table->boolean('include_holidays')->default(true);
            $table->boolean('include_overtime')->default(true);
            $table->timestamp('last_accessed_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_feeds');
    }
};
