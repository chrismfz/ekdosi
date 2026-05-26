<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Each quantity-break threshold should appear at most once per product.
 * The original migration left `(product_id, qty)` non-unique, so an
 * operator could land two tiers at qty=10 — one with a flat price, one
 * with a discount % — and any future "find the applicable tier for
 * line qty X" lookup would non-deterministically pick one (insert
 * order in sqlite, undefined in MariaDB on tied sorts).
 *
 * Surfaced in the PR #20 code review. The IssueInvoice action that
 * lands later will surface tiers as a hint, and ambiguity at that
 * stage would silently change which discount appears.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_price_tiers', function (Blueprint $t) {
            $t->unique(['product_id', 'qty']);
        });
    }

    public function down(): void
    {
        Schema::table('product_price_tiers', function (Blueprint $t) {
            $t->dropUnique(['product_id', 'qty']);
        });
    }
};
