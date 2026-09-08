<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Forward-looking columns on customers, added before the CustomerResource
 * lands so we don't need to ALTER mid-feature later. None of these have
 * a legacy column to import from; the ETL leaves them at defaults and
 * downstream features fill them in.
 *
 * - needs_immediate_invoice: the γκρινιάρης flag. Synced from the WHMCS
 *   "γκρινιάρης" checkbox when the WHMCS bridge lands; flips the
 *   IssueInvoice scheduled command's batching decision per-customer
 *   (immediate on payment vs. weekly roll-up).
 * - is_active: lifecycle without deleting. Future WHMCS sync target for
 *   Active / Inactive / Closed → boolean. Filament default-filters
 *   inactive out.
 * - peppol_endpoint: Estonian PEPPOL submission needs a per-customer
 *   endpoint identifier (Estonian companies use their Äriregistri kood).
 *   Greek myDATA submission doesn't need it — column stays null for GR
 *   customers, visible+optional on the form only when the tenant's
 *   country_code = EE.
 * - referred_by_customer_id: nullable FK to another customer in the
 *   same tenant. Self-referential, set null on delete (preserves the
 *   referee even if the referrer is removed).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $t) {
            $t->boolean('needs_immediate_invoice')
                ->default(false)
                ->after('payment_method_id');

            $t->boolean('is_active')
                ->default(true)
                ->after('needs_immediate_invoice');

            $t->string('peppol_endpoint', 255)
                ->nullable()
                ->after('is_active');

            $t->foreignId('referred_by_customer_id')
                ->nullable()
                ->after('peppol_endpoint')
                ->constrained('customers')
                ->nullOnDelete();

            $t->index(['company_id', 'is_active']);
            $t->index(['company_id', 'needs_immediate_invoice']);
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $t) {
            $t->dropIndex(['company_id', 'is_active']);
            $t->dropIndex(['company_id', 'needs_immediate_invoice']);
            $t->dropConstrainedForeignId('referred_by_customer_id');
            $t->dropColumn(['needs_immediate_invoice', 'is_active', 'peppol_endpoint']);
        });
    }
};
