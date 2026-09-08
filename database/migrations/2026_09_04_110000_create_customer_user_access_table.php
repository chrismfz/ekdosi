<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer-portal ACCESS GRANTS — «ποιο login βλέπει ποιον πελάτη, σε ποια
 * εταιρία». The bridge between the GLOBAL login identity (`customer_users`) and
 * the per-tenant `customers` rows it may view. One `customer_users` row can hold
 * MANY grants (same email in several companies, or a reseller who sees the third
 * parties they routed), which is exactly what solves the «ίδιο email σε πολλές
 * εταιρίες» problem.
 *
 * Every grant is EXPLICIT and AUDITED (granted_by / granted_at) and revoked
 * softly (revoked_at, keeping the trail) — this is the operator's «προσθέτει/
 * αφαιρεί linked users ανά εταιρία» surface, and the future leak-proof boundary
 * for the portal's documents view (a login sees a customer's docs ONLY through
 * an active grant).
 *
 * NOTE: this table exists but is not yet READ by any customer-facing screen —
 * the portal documents view is a later slice. This slice is the data model +
 * operator management only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_user_access', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_user_id')->constrained('customer_users')->cascadeOnDelete();
            // company_id is redundant with customers.company_id but kept explicit
            // for per-company scoping/UI and set from the chosen customer on write.
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            // 'owner' (the party the customer row represents) | 'reseller' (brought
            // the customer via «Παραστατικά σε τρίτους»). Extensible string.
            $table->string('role')->default('owner');
            // Audit: which operator granted it (null = system/backfill) and when.
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('granted_at')->nullable();
            // Soft revoke — keep the row for audit; an active grant has revoked_at NULL.
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            // One grant per (login, company, customer).
            $table->unique(['customer_user_id', 'company_id', 'customer_id'], 'cua_login_company_customer_unique');
            $table->index(['company_id', 'customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_user_access');
    }
};
