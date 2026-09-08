<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gapless-at-send numbering (reverses MON-4). The real ΑΑ (`code`) and its
 * `invcode` are now allocated at the moment a document is TRANSMITTED to
 * myDATA/provider, not at draft creation — so a draft or a finalized-but-unsent
 * document carries a PROVISIONAL identity (`code` NULL, `invcode` «ΠΡΟΣ-ΤΠΥ-{id}»)
 * and consumes no number. This makes the sequence the ΑΑΔΕ sees always continuous.
 *
 * Two schema changes on the `invoices` table:
 *   - `code` becomes NULLABLE (no ΑΑ until issued).
 *   - `invcode` becomes NULLABLE and widens 15 → 30: the provisional string
 *     «ΠΡΟΣ-{type}-{id}» is longer than a real invcode («ΤΠΥ6661»), and a
 *     5-char type + 7-digit id overflows varchar(15).
 *
 * The `unique(company_id, invcode)` holds: distinct-per-id provisional strings
 * are unique, and MySQL/MariaDB allows multiple NULLs in a unique index (the
 * brief window between INSERT and the model's provisional-invcode hook).
 *
 * `delivery_notes` is DELIBERATELY untouched: Phase 1 is invoices only, delivery
 * notes still allocate at create, and loosening their NOT NULL guard before they
 * carry a provisional identity would only remove a safety net. Phase 2 migrates
 * them when it actually moves their allocation to send.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $t): void {
            $t->string('invcode', 30)->nullable()->change();
            $t->unsignedBigInteger('code')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Reverting to NOT NULL requires that no provisional (unissued) rows exist;
        // this is a best-effort restore of the original column shape.
        Schema::table('invoices', function (Blueprint $t): void {
            $t->string('invcode', 15)->nullable(false)->change();
            $t->unsignedBigInteger('code')->nullable(false)->change();
        });
    }
};
