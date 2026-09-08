<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Domains pillar (Πυλώνας A / A1) — docs/domains/README.md §3.2.
 *
 * The per-tenant TLD catalogue + rules: which registrar connection handles the
 * TLD (= the WHMCS «Auto Registration» dropdown, the routing table), the term
 * limits (.gr → min_years=2, even multiples), label limits, grace/redemption
 * windows + fees, and the per-TLD capability flags. Prices live in
 * domain_tld_prices (register ≠ renew ≠ transfer ≠ restore, per explicit year).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('domain_tlds', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('tld', 30);                       // 'gr', 'com', 'com.gr', 'ελ' …
            $t->foreignId('registrar_connection_id')     // ποιός registrar (routing) — nullable = ακαθόριστο/manual
                ->nullable()->constrained('domain_registrar_connections')->nullOnDelete();
            $t->unsignedTinyInteger('min_years')->default(1);
            $t->unsignedTinyInteger('max_years')->default(10);
            $t->unsignedTinyInteger('min_chars')->default(3);
            $t->unsignedTinyInteger('max_chars')->default(63);
            $t->boolean('allow_idn')->default(false);
            $t->boolean('allow_transfer')->default(true);
            $t->unsignedSmallInteger('grace_period_days')->default(0);
            $t->decimal('grace_fee', 14, 2)->default(0);
            $t->unsignedSmallInteger('redemption_period_days')->default(0);
            $t->decimal('redemption_fee', 14, 2)->default(0);
            // Capability flags per TLD (deferred features keep their flags — §11).
            $t->boolean('dns_management')->default(false);
            $t->boolean('email_forwarding')->default(false);
            $t->boolean('id_protection')->default(false);
            $t->boolean('epp_code')->default(true);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->softDeletes();

            $t->unique(['company_id', 'tld']);
            $t->index(['company_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domain_tlds');
    }
};
