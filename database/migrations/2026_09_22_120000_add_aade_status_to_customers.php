<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #8 «Ανενεργό ΑΦΜ»: persist the AADE/GSIS registry activity status of a
 * customer's ΑΦΜ, so an inactive (closed-business) counterpart is VISIBLE at a
 * glance and re-checkable on a schedule — not only discoverable by opening the
 * on-demand «Διασταύρωση ΑΦΜ με ΑΑΔΕ» modal.
 *
 * Three columns, all nullable (null = never checked):
 *   - aade_active            : the registry activity boolean (AadeRegistryRecord::active)
 *   - aade_status_descr      : the raw Greek status text (e.g. «ΕΝΕΡΓΟΣ ΑΦΜ»)
 *   - aade_status_checked_at : when we last got an answer from GSIS
 *
 * DISTINCT from `is_active` (the operator's own business «ενεργός πελάτης» flag):
 * this reflects what the ΑΑΔΕ says, never overwrites the operator's flag, and
 * never blocks issuing (informational only).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->boolean('aade_active')->nullable()->after('is_active');
            $table->string('aade_status_descr', 120)->nullable()->after('aade_active');
            $table->timestamp('aade_status_checked_at')->nullable()->after('aade_status_descr');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropColumn(['aade_active', 'aade_status_descr', 'aade_status_checked_at']);
        });
    }
};
