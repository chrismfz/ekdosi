<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Canned (predefined) replies in categories (Πυλώνας E) — WHMCS «Predefined
 * Replies» parity. The tenant's real ones are invoicing-shaped and Greek
 * (Invoices → InvoiceSend, ΑπόδειξηΠαροχής, Επιβεβαίωση πληρωμής, Τραπεζικοί
 * λογαριασμοί). `body` supports template tokens expanded at insert time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('canned_reply_categories', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('name', 120);
            $t->unsignedInteger('sort')->default(0);
            $t->timestamps();
            $t->unique(['company_id', 'name']);
        });

        Schema::create('canned_replies', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('canned_reply_category_id')->nullable()->constrained()->nullOnDelete();
            $t->string('title', 160);
            $t->text('body');
            $t->unsignedInteger('sort')->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('canned_replies');
        Schema::dropIfExists('canned_reply_categories');
    }
};
