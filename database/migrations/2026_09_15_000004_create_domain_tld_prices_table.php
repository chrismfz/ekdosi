<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Domains pillar (Πυλώνας A / A1) — docs/domains/README.md §3.3.
 *
 * Price per (TLD × operation × EXPLICIT year × currency) — the asymmetry
 * ProductBillingPrice cannot express: register ≠ renew ≠ transfer ≠ restore,
 * and a 2-year price is NOT always 2 × the 1-year (proof: .eu 2yr 26.50 ≠
 * 13.50×2; .gr sells even years only). `is_enabled` is the WHMCS «-1 disables
 * this term» as a proper flag. `cost` is what the registrar charges us (synced
 * on A2 for registrars with supportsPricingSync, manual otherwise); `price` is
 * the sell price.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('domain_tld_prices', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('domain_tld_id')->constrained('domain_tlds')->cascadeOnDelete();
            $t->string('operation', 20);            // register | transfer | renewal | restore | redemption
            $t->unsignedTinyInteger('years');       // 1–10, explicit per-year rows
            $t->char('currency', 3)->default('EUR');
            $t->decimal('cost', 14, 2)->nullable(); // registrar cost (A2 sync / manual)
            $t->decimal('price', 14, 2)->nullable(); // sell price
            $t->boolean('is_enabled')->default(true);
            $t->timestamps();

            $t->unique(['domain_tld_id', 'operation', 'years', 'currency']);
            $t->index(['company_id', 'domain_tld_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domain_tld_prices');
    }
};
