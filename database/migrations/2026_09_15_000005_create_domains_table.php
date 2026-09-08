<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Domains pillar (Πυλώνας A / A1) — the core table, docs/domains/README.md §3.4.
 *
 * Two orthogonal clocks (the local_status × mydata_state discipline):
 * `expires_at` is REGISTRAR truth (pulled by the A2 sync), the linked
 * ServiceContract's next_due_date is the BILLING clock — reconciled, never
 * conflated. `customer_id` is NULLABLE by design: an imported/unmatched domain
 * is a legal «αδέσποτο» (no ServiceContract → outside renewal billing) until
 * the operator runs «Ανάθεση σε πελάτη». The authoritative name is sld + tld;
 * fqdn is derived and unique per tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('domains', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->unsignedInteger('legacy_id')->nullable();     // WHMCS domain id (optional bootstrap hint)
            $t->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('service_contract_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('domain_tld_id')->constrained('domain_tlds')->restrictOnDelete();
            $t->foreignId('registrar_connection_id')          // value wins; the TLD's is the default hint
                ->nullable()->constrained('domain_registrar_connections')->nullOnDelete();
            $t->string('sld', 190);
            $t->string('tld', 30);
            $t->string('fqdn', 190);                          // derived (sld.tld) — kept for lookups/unique
            $t->string('status', 30)->default('active');      // App\Enums\DomainStatus
            $t->date('registered_at')->nullable();
            $t->date('transferred_at')->nullable();           // set when an inbound transfer completes
            $t->date('expires_at')->nullable();               // REGISTRAR truth (sync clock)
            $t->boolean('auto_renew')->default(false);
            $t->boolean('transfer_lock')->default(false);
            $t->boolean('whois_privacy')->default(false);
            $t->boolean('dnssec_enabled')->default(false);
            $t->boolean('consent_publish')->default(false);   // GDPR: default redacted
            $t->string('registrar_domain_id', 60)->nullable(); // e.g. Openprovider numeric id
            $t->string('idn_script', 30)->nullable();          // .ελ / IDN
            $t->timestamp('last_synced_at')->nullable();
            $t->text('sync_error')->nullable();
            // Per-domain overrides — null = fall back to the TLD row.
            $t->unsignedSmallInteger('grace_days_override')->nullable();
            $t->unsignedSmallInteger('redemption_days_override')->nullable();
            $t->decimal('fee_override', 14, 2)->nullable();
            $t->decimal('price_override', 14, 2)->nullable();
            $t->json('module_meta')->nullable();
            $t->timestamps();
            $t->softDeletes();

            $t->unique(['company_id', 'fqdn']);
            $t->unique(['company_id', 'legacy_id']);
            $t->index(['company_id', 'status']);
            $t->index(['company_id', 'expires_at']);
            $t->index(['company_id', 'customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domains');
    }
};
