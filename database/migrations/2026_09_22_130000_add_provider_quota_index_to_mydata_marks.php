<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Index `mydata_marks(company_id, provider_key, id)` for the provider-quota lookup.
 *
 * The dashboard card and `GrProviderSubmitter::warnIfLowProviderQuota()` both run
 * `WHERE company_id = ? AND provider_key = ? AND remaining_invoices IS NOT NULL
 * ORDER BY id DESC LIMIT 1`. The table carried only `index(invoice_id)` +
 * `unique(company_id, legacy_id)`, so the planner walked the PK backwards. This
 * composite lets the filter seek and the trailing `id` serve the ORDER BY / LIMIT.
 *
 * Plain index (not unique): many marks share a (company_id, provider_key).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mydata_marks', function (Blueprint $table): void {
            $table->index(['company_id', 'provider_key', 'id'], 'mydata_marks_company_provider_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('mydata_marks', function (Blueprint $table): void {
            $table->dropIndex('mydata_marks_company_provider_id_index');
        });
    }
};
