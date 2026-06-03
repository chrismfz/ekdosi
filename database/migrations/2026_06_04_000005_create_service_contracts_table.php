<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Service contract = the per-customer subscription INSTANCE (the WHMCS «Service»).
 * The product is the shared catalogue template; THIS row is "customer X has it,
 * billed every Y, next due Z, status W, on server S". It is NOT money — it's the
 * scheduler that STAGES draft invoices when next_due_date arrives (operator-gated,
 * never auto-AADE). The generated invoice carries the money via the normal
 * invoices/InvoiceBalance path (invoices.service_contract_id is the provenance).
 *
 * amount/billing_cycle/vat_percent are a SNAPSHOT (copied from the product's
 * billing-price matrix at create time) so later catalogue edits don't rewrite a
 * live subscription. provisioning_module/module_meta/server_id carry the (future,
 * native, WHMCS-independent) automation hooks — license key / cPanel user /
 * mailcow domain live in module_meta, written/read by a future module with no
 * schema change. whmcs_service_id is a legacy-correlation slot only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_contracts', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('legacy_id')->nullable();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('customer_id')->constrained()->restrictOnDelete();
            $t->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('invoice_type_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('server_id')->nullable()->constrained()->nullOnDelete();

            $t->string('description', 255)->nullable();
            $t->string('billing_cycle', 20);            // App\Enums\BillingCycle value
            $t->decimal('amount', 14, 2)->default(0);   // recurring price (snapshot)
            $t->decimal('setup_fee', 14, 2)->default(0);
            $t->decimal('vat_percent', 5, 2)->default(0);

            $t->string('status', 20)->default('pending'); // App\Enums\ServiceContractStatus value
            $t->date('start_date')->nullable();
            $t->date('next_due_date')->nullable();
            $t->date('end_date')->nullable();
            $t->dateTime('last_invoiced_at')->nullable();
            $t->dateTime('suspended_at')->nullable();
            $t->dateTime('terminated_at')->nullable();
            $t->string('cancel_reason', 255)->nullable();

            $t->string('domain', 190)->nullable();        // hosting/email context
            $t->string('provisioning_module', 40)->default('none');
            $t->json('module_meta')->nullable();          // license key / cpanel user / mailcow domain / API ids

            $t->unsignedBigInteger('whmcs_service_id')->nullable(); // legacy slot only

            $t->text('notes')->nullable();
            $t->timestamps();
            $t->softDeletes();

            $t->unique(['company_id', 'legacy_id']);
            $t->index(['company_id', 'status', 'next_due_date']);
            $t->index(['company_id', 'customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_contracts');
    }
};
