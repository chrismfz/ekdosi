<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Leads — υποψήφιοι πελάτες (mini-CRM, pre-customer). Net-new concept, no
 * legacy source. Everything except the name is optional: a lead is whoever we
 * found, with whatever we know about them so far. See docs/leads-mini-crm.md.
 *
 * `converted_customer_id` is THE lead↔customer link (unique — one lead becomes
 * one customer; the customer side reads it back via Customer::originLead()).
 * `last_activity_at` is a cache written only by LeadActivityObserver.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();

            // Identity — only `name` is required.
            $t->string('name');
            $t->string('contact_person')->nullable();
            $t->string('phone', 60)->nullable();
            $t->string('mobile', 60)->nullable();
            $t->string('email')->nullable();
            $t->string('website')->nullable();
            $t->string('afm', 20)->nullable();
            $t->string('address1')->nullable();
            $t->string('city', 120)->nullable();
            $t->string('postcode', 20)->nullable();
            $t->string('country', 2)->nullable();
            $t->string('occupation')->nullable();

            // Tracking.
            $t->string('source', 30)->nullable();
            $t->foreignId('referred_by_customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $t->string('status', 20)->default('new');
            $t->string('lost_reason')->nullable();
            $t->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->dateTime('next_action_at')->nullable();
            $t->dateTime('last_activity_at')->nullable();

            // Conversion link (L1 fills it; the column exists from day one so
            // the model/relations are stable).
            $t->foreignId('converted_customer_id')->nullable()->unique()->constrained('customers')->nullOnDelete();
            $t->dateTime('converted_at')->nullable();

            $t->text('notes')->nullable();
            $t->timestamps();
            $t->softDeletes();

            $t->index(['company_id', 'status']);
            $t->index(['company_id', 'afm']);
            $t->index(['company_id', 'assigned_user_id']);
            $t->index(['company_id', 'next_action_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
