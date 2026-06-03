<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PR-D — the dunning «Knob».
 *
 * Dunning (auto suspend/terminate of a contract whose renewal went overdue) is
 * DESTRUCTIVE, so it's opt-in per PRODUCT — `products.dunning_enabled` defaults
 * FALSE. A fresh deploy with the scheduler ON is still a no-op until an operator
 * flips a product on. A contract can override its product with a NULLABLE
 * `service_contracts.dunning_enabled` (null = inherit the product's flag;
 * true/false = force on/off for this one contract).
 *
 * The day-count thresholds already exist (products.default_suspend_after_days /
 * default_terminate_after_days + the per-contract suspend_after_days /
 * terminate_after_days overrides) — this migration only adds the on/off switch.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $t) {
            // The per-product master switch. Default OFF = the safety: nothing
            // is ever suspended/terminated until the operator opts a product in.
            $t->boolean('dunning_enabled')->default(false)->after('default_terminate_after_days');
        });

        Schema::table('service_contracts', function (Blueprint $t) {
            // Per-contract override of the product flag. NULL = inherit.
            $t->boolean('dunning_enabled')->nullable()->after('terminate_after_days');
            // Set ONLY when the dunning engine itself suspended the contract.
            // The auto-unsuspend reactivates ONLY contracts carrying this marker,
            // so it can never undo a MANUAL «Αναστολή» (abuse/fraud/customer hold)
            // of a paid-up contract. Cleared on any reactivation.
            $t->dateTime('dunning_suspended_at')->nullable()->after('suspended_at');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $t) {
            $t->dropColumn('dunning_enabled');
        });
        Schema::table('service_contracts', function (Blueprint $t) {
            $t->dropColumn(['dunning_enabled', 'dunning_suspended_at']);
        });
    }
};
