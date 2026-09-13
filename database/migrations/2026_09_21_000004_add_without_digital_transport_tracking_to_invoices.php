<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Combined ΤΔΑ — Slice 3b (`docs/combined-tda-design.md` §3/§6).
 *
 * The v2.0.2 `withoutDigitalTransportTracking` fork: when TRUE, a ΤΔΑ files and
 * goes straight to *Completed* with NO qrUrl and NO movement lifecycle; default
 * FALSE = tracking on (qrUrl returned, the full RegisterTransfer→… lifecycle
 * applies). Per-document, operator-chosen on the ΤΔΑ form (3d). Nullable/false
 * default → every existing + plain invoice is unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $t) {
            $t->boolean('without_digital_transport_tracking')->default(false)->after('is_delivery_note');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $t) {
            $t->dropColumn('without_digital_transport_tracking');
        });
    }
};
