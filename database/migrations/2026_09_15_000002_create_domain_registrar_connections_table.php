<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Domains pillar (Πυλώνας A / A0) — docs/domains/README.md §3.1.
 *
 * One row per (company × registrar account), mirroring
 * `payment_gateway_connections`: `label` disambiguates, `is_active` toggles,
 * `config` holds the per-account creds ENCRYPTED at rest (model `encrypted:array`
 * cast). Deliberately NO unique on (company, registrar) — a tenant may hold two
 * accounts of the same registrar. `mode` picks the endpoint set the adapter
 * talks to; fail-safe direction is the ProviderCredentials idiom (only an
 * explicit 'production' is live).
 *
 * Runtime behaviour is resolved by the `registrar` key through
 * DomainRegistrarRegistry ('manual' = the API-less Null adapter). Adding a
 * registrar = one class + one config line; no schema change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('domain_registrar_connections', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('registrar', 40);              // 'manual' | 'openprovider' (A2) | 'grepp' (A4)
            $t->string('label')->nullable();          // «Openprovider MyIP», «FORTH EPP»
            $t->boolean('is_active')->default(false); // opt-IN: off until configured
            $t->string('mode', 20)->default('off');   // 'off' | 'sandbox' | 'production'
            $t->text('config')->nullable();           // encrypted JSON (API creds / EPP host+user+pass)
            $t->timestamps();
            $t->softDeletes();

            $t->index(['company_id', 'is_active']);
            $t->index(['company_id', 'registrar']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domain_registrar_connections');
    }
};
