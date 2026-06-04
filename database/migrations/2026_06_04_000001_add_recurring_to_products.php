<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recurring + provisioning catalogue metadata on products. A product becomes a
 * subscription template when `is_recurring`; its per-cycle prices live in
 * `product_billing_prices` (the WHMCS-style matrix). `provisioning_module` is a
 * free-form key (NOT an enum) — 'none' (manual) / 'custom' (electronic,
 * operator-handled) / later 'cpanel'/'mailcow'/'directadmin'/'license_server'/
 * 'antivirus_reseller'… — so a future native (WHMCS-independent) module drops in
 * with NO migration. `module_meta` holds per-product module config (remote
 * package id, plan…). Schema only here — no live provisioning yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $t) {
            $t->boolean('is_recurring')->default(false)->after('is_active');
            $t->string('provisioning_module', 40)->default('none')->after('is_recurring');
            $t->json('module_meta')->nullable()->after('provisioning_module');
            // Default dunning thresholds for contracts created from this product
            // (overridable per contract). Schema only — dunning is a later phase.
            $t->unsignedSmallInteger('default_suspend_after_days')->nullable()->after('module_meta');
            $t->unsignedSmallInteger('default_terminate_after_days')->nullable()->after('default_suspend_after_days');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $t) {
            $t->dropColumn([
                'is_recurring', 'provisioning_module', 'module_meta',
                'default_suspend_after_days', 'default_terminate_after_days',
            ]);
        });
    }
};
