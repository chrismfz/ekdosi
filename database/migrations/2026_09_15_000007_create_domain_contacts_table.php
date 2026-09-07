<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Domains pillar (Πυλώνας A / A1) — docs/domains/README.md §3.7.
 *
 * The registrant/admin/tech/billing contacts OWNED by the domain — a first-class
 * entity, deliberately NOT the ekdosi Customer (pre-filled from one with
 * explicit confirmation; may diverge). Our own copy of the fields is kept even
 * when the registrar stores them as reusable handles
 * (`registrar_contact_handle`, e.g. Openprovider AB123456-XX). One contact per
 * type per domain. These are ALSO the operator's manual-assign aid: an imported
 * unassigned domain shows its parsed contacts so the operator can match it to a
 * customer without any WHMCS dependency (owner decision, §9).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('domain_contacts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('domain_id')->constrained('domains')->cascadeOnDelete();
            $t->string('type', 20);                 // registrant | admin | tech | billing
            $t->string('name', 190);
            $t->string('org', 190)->nullable();
            $t->string('email', 190)->nullable();
            $t->string('phone', 40)->nullable();
            $t->string('address1', 190)->nullable();
            $t->string('address2', 190)->nullable();
            $t->string('city', 120)->nullable();
            $t->string('postcode', 20)->nullable();
            $t->char('country', 2)->nullable();
            $t->string('registrar_contact_handle', 40)->nullable();
            $t->timestamps();

            $t->unique(['domain_id', 'type']);
            $t->index(['company_id', 'domain_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domain_contacts');
    }
};
