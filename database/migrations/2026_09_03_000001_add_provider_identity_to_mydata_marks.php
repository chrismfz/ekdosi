<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PROV-003 (bucket B, snapshot half): freeze the provider identity-in-force per
 * document.
 *
 * The printed representation (and the invoice page) show the provider's
 * commercial/legal name, AADE code, website and — legally-critical — the ΥΠΑΗΕΣ
 * LICENCE number. Those are read from immutable config (`ProviderIdentity`), so a
 * future licence rotation (`…_V1_…` → `…_V2_…`) would silently rewrite the evidence
 * on every ALREADY-issued document when it is reprinted. A.1112/2025 wants the
 * licence in force AT ISSUE preserved.
 *
 * Snapshot the whole identity as JSON on the PROVIDER_INSERT mark at issue time.
 * Nullable + additive: direct-myDATA marks and every pre-existing row leave it
 * null, and both the PDF renderer and the invoice page fall back to the current
 * config identity for those (correct today — one stable licence). One column, the
 * full identity, round-tripped through the ProviderIdentity value object so the
 * consumers read snapshot and config through the exact same shape.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('mydata_marks', 'provider_identity')) {
            return;
        }

        Schema::table('mydata_marks', function (Blueprint $t) {
            // JSON, nullable, right after the provider_key it snapshots for.
            $t->json('provider_identity')->nullable()->after('provider_key');
        });
    }

    public function down(): void
    {
        Schema::table('mydata_marks', function (Blueprint $t) {
            $t->dropColumn('provider_identity');
        });
    }
};
