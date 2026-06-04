<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The WHMCS-style per-cycle price matrix for a recurring product: one row per
 * product × billing cycle, with an optional setup fee and an enable flag (so a
 * product can offer e.g. only Annually + Biennially). When a contract is
 * created the operator picks an enabled cycle and its price is copied onto the
 * contract (snapshot). Distinct from `product_price_tiers` (quantity tiers).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_billing_prices', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('product_id')->constrained()->cascadeOnDelete();
            $t->string('billing_cycle', 20);          // App\Enums\BillingCycle value
            $t->decimal('setup_fee', 14, 2)->default(0);
            $t->decimal('price', 14, 2)->default(0);
            $t->boolean('is_enabled')->default(true);
            $t->timestamps();
            $t->unique(['product_id', 'billing_cycle']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_billing_prices');
    }
};
