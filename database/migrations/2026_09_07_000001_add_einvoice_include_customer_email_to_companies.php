<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-company knob: include the CUSTOMER's email in the provider (InvoSign)
 * XML — <CounterpartEmail>.
 *
 *   companies.einvoice_include_customer_email  (default FALSE)
 *
 * The provider uses that email to DELIVER the document to the customer. On the
 * dev/sandbox environment this meant test invoices were being emailed to real
 * customers. ekdosi has its OWN invoice-email flow (SendInvoiceEmail /
 * auto_email_*), so the provider-side delivery is redundant anyway.
 *
 * Default OFF so no customer email leaves in the provider XML until a tenant
 * explicitly opts in (e.g. to test provider-side delivery on purpose). Affects
 * ONLY the provider (gr-provider) channel — the AADE/myDATA payload carries no
 * email at all. When OFF the field is emitted EMPTY, exactly as it already is
 * for a customer with no email on file, so the provider never sees a shape
 * change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->boolean('einvoice_include_customer_email')
                ->default(false)
                ->after('einvoice_provider_mode');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropColumn('einvoice_include_customer_email');
        });
    }
};
