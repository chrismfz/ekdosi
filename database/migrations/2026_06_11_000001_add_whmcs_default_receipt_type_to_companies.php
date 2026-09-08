<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Type-aware auto-issue: a per-tenant DEFAULT RECEIPT type, alongside the
 * existing default invoice type.
 *
 *   companies.whmcs_default_receipt_type_id  (nullable FK)
 *     The «Απόδειξη λιανικής» type whmcs:auto-issue uses when the row's intent
 *     is a receipt — i.e. the customer did NOT ask for a τιμολόγιο
 *     (wantsinvoice=false), or a SINGLE third-party route is flagged
 *     `is_receipt`. Without it, auto-issue can't honor a receipt intent, so it
 *     HOLDS such rows for the operator instead of silently issuing the wrong
 *     type (the legacy «primary VAT decides everything» bug). nullOnDelete so
 *     deleting the type doesn't cascade the tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->foreignId('whmcs_default_receipt_type_id')
                ->nullable()
                ->after('whmcs_default_invoice_type_id')
                ->constrained('invoice_types')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('whmcs_default_receipt_type_id');
        });
    }
};
