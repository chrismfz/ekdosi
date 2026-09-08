<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WHMCS payment gateway → ekdosi payment method map (the §8.12 payment-means twin
 * of whmcs_income_maps). A WHMCS invoice records HOW it was paid in its
 * `paymentmethod` (the gateway system name: banktransfer / stripe / paypal …); the
 * inbox used to ignore it and take the payment method from the invoice TYPE default
 * (usually cash → §8.12 type 3), so a card/bank-paid invoice filed as «Μετρητά».
 *
 * This declares, per gateway, which ekdosi PaymentMethod a WHMCS invoice on that
 * gateway is issued with — and the ekdosi method carries the §8.12 type (and the
 * due-days term), so the myDATA payload gets the right payment means automatically.
 * Resolved gateway→method by App\Services\WhmcsInbox\WhmcsPaymentMethodResolver;
 * unmapped gateways fall back to the invoice type default (unchanged behaviour).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whmcs_payment_maps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            // The WHMCS gateway system name (tblinvoices.paymentmethod), e.g.
            // 'banktransfer', 'stripe', 'paypal'. Lower-cased/trimmed on save.
            $table->string('whmcs_gateway', 64);
            // The ekdosi payment method a WHMCS invoice on this gateway issues with;
            // it carries the §8.12 type + due-days. If the method is deleted the
            // mapping is meaningless, so it goes with it. NULLABLE so a portability
            // re-import whose payment-method FK can't be rewired nulls the column
            // (like customers.payment_method_id) instead of aborting the whole import;
            // the resolver ignores a null-method row (whereHas).
            $table->foreignId('payment_method_id')->nullable()->constrained()->cascadeOnDelete();
            // Display convenience: the WHMCS gateway's friendly name at mapping time.
            $table->string('label', 191)->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'whmcs_gateway']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whmcs_payment_maps');
    }
};
