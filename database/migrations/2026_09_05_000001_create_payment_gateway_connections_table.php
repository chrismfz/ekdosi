<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payment gateways (Πυλώνας B / B0) — docs/payment-gateways-design.md §4.
 *
 * One row per (company × configured gateway), mirroring `billing_connections`:
 * the WHMCS-style «Τρόποι πληρωμής» list. Each row is independently toggleable
 * (`is_active` = shown to the customer or not), carries a customer-facing
 * `label` (display name) and a `sort` (order in the portal picker), and holds
 * its per-tenant settings/creds in `config` (ENCRYPTED at rest via the model's
 * `encrypted:array` cast). Two connections of the same gateway are allowed
 * (e.g. two accounts) — `label` disambiguates, so no unique on (company, gateway).
 *
 * Selection of runtime behaviour is by the `gateway` key →
 * App\Services\Payments\PaymentGatewayRegistry. Add a gateway = one class + one
 * config line; no schema change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_gateway_connections', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('gateway', 40);                 // 'manual' | 'stripe' | 'paypal' | 'eurobank' …
            $t->string('label')->nullable();           // customer-facing name («Κάρτα», «Κατάθεση»)
            $t->boolean('is_active')->default(false);  // opt-IN: a new method is off until configured
            $t->unsignedInteger('sort')->default(0);   // order in the portal picker
            $t->text('config')->nullable();            // encrypted JSON (creds + display settings)
            $t->timestamps();
            $t->softDeletes();

            $t->index(['company_id', 'is_active']);
            $t->index(['company_id', 'gateway']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_gateway_connections');
    }
};
