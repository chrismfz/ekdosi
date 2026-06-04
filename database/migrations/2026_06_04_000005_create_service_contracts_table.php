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
            // Payment method stamped onto each staged renewal draft. CRITICAL:
            // a credit-term method (due_days>0, e.g. bank deposit) makes the
            // renewal a real receivable that can go overdue → dunning works;
            // leaving it null would make every renewal cash-term «settled at
            // issue». Falls back to the invoice type's default when null.
            $t->foreignId('payment_method_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('server_id')->nullable()->constrained()->nullOnDelete();

            $t->string('description', 255)->nullable();
            $t->string('billing_cycle', 20);            // App\Enums\BillingCycle value
            $t->decimal('quantity', 9, 3)->default(1);  // WHMCS «quantity» — units of the recurring charge
            $t->decimal('amount', 14, 2)->default(0);   // recurring price per unit (snapshot)
            $t->decimal('setup_fee', 14, 2)->default(0);
            $t->decimal('vat_percent', 5, 2)->default(0);

            $t->string('status', 20)->default('pending'); // App\Enums\ServiceContractStatus value
            $t->date('start_date')->nullable();
            $t->date('next_due_date')->nullable();
            $t->date('end_date')->nullable();
            $t->dateTime('last_invoiced_at')->nullable();
            // The renewal invoice that last advanced next_due_date. The cursor
            // advances ON ISSUE (draft→active) of a contract-linked invoice, NOT
            // at stage time — so an un-billed/un-paid renewal keeps next_due in
            // the past (the dunning signal). This guard makes the advance fire
            // exactly once per invoice (re-finalize is idempotent).
            $t->foreignId('last_renewal_invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $t->dateTime('suspended_at')->nullable();
            $t->dateTime('terminated_at')->nullable();
            $t->string('cancel_reason', 255)->nullable();
            // Dunning thresholds (override of the product defaults): days a linked
            // invoice can stay overdue before auto-suspend / auto-terminate. NULL
            // = use the product default / no auto action. Schema only — the
            // dunning command is a later phase (uses these + invoice overdue).
            $t->unsignedSmallInteger('suspend_after_days')->nullable();
            $t->unsignedSmallInteger('terminate_after_days')->nullable();

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
