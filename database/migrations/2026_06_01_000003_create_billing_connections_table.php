<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 0 of the Bridges/Connectors seam (docs/bridges-connectors.md).
 *
 * A tenant can run SEVERAL billing systems at once (e.g. WHMCS + a WooCommerce
 * shop, or two WooCommerce shops). So the source is NOT a single column on
 * `companies` — it's a registry: one row per (company × connected system), each
 * independently toggleable (`is_active` = the «Γέφυρες» tab on/off switch).
 *
 * Phase 0 only REGISTERS connections (drives the seam + future UI). The actual
 * WHMCS credentials still live on `companies.whmcs_*`; Phase 1 moves per-source
 * settings into `config`. Two connections of the SAME source are allowed (two
 * shops) — hence no unique on (company_id, source); `label` disambiguates.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_connections', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('source', 40);                 // 'whmcs' | 'woocommerce' | 'blesta' …
            $t->string('label')->nullable();          // «Κύριο WHMCS», «Shop EU» …
            $t->boolean('is_active')->default(true);
            $t->json('config')->nullable();           // per-connection settings (Phase 1)
            $t->timestamps();
            $t->softDeletes();

            $t->index(['company_id', 'source']);
            $t->index(['company_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_connections');
    }
};
