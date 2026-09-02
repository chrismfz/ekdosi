<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MYD-009: widen `invoices.company_name` to match its source.
 *
 * `invoices.company_name` was varchar(120) while `customers.name` is varchar(191),
 * so freezing the reported party could not always record it faithfully. Two ways
 * that bit:
 *
 *  - under MySQL strict mode an over-long copy RAISES, and the freeze runs inside
 *    the same transaction as the MARK audit row — discarding the record of a filing
 *    AADE had already accepted and leaving the invoice permanently stuck;
 *  - truncating instead makes the frozen "what we reported" differ from what was
 *    actually filed, silently.
 *
 * Widening removes the conflict at the source; the per-column truncation in
 * Invoice::frozenPartyColumns() stays as a belt-and-braces guard.
 *
 * `vies_vat` diverged the same way (varchar(20) against `customers.vat_vies`
 * varchar(30)), so it is widened too. The rest already match their customer
 * counterparts exactly (address1 60, address2 60, city 60, postcode 10,
 * occupation 120). Widening a varchar is an in-place, additive change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->string('company_name', 191)->nullable()->change();
            $table->string('vies_vat', 30)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Narrowing again would truncate real data, so down() only restores the
        // declared type for rows that still fit; MariaDB refuses otherwise, which is
        // the honest outcome.
        Schema::table('invoices', function (Blueprint $table): void {
            $table->string('company_name', 120)->nullable()->change();
            $table->string('vies_vat', 20)->nullable()->change();
        });
    }
};
