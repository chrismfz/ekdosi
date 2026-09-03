<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gapless-at-send numbering — Phase 2, δελτία αποστολής (twin of the Phase 1
 * invoices migration 2026_09_04_000001). The real ΑΑ (`code`) + `invcode` are now
 * allocated at the moment a Δελτίο Αποστολής is TRANSMITTED to myDATA/provider
 * (InvoiceNumberer::assignDelivery, called by DeliveryNoteSubmitter), not at draft
 * creation — so a draft carries a PROVISIONAL identity (`code` NULL, `invcode`
 * «ΠΡΟΣ-{τύπος}-{id}») and consumes no number. The transmitted 9.x sequence the
 * ΑΑΔΕ sees therefore never gaps from an abandoned or cancelled draft.
 *
 * Two schema changes on `delivery_notes`, mirroring the invoices table:
 *   - `code` becomes NULLABLE (no ΑΑ until issued).
 *   - `invcode` becomes NULLABLE and widens 15 → 30: the provisional string
 *     «ΠΡΟΣ-ΔΑΠ-6885» is longer than a real invcode («ΔΑΠ1»), and a multi-char
 *     Greek type + a large id would overflow varchar(15).
 *
 * The `unique(company_id, invcode)` holds: distinct-per-id provisional strings are
 * unique, and MariaDB allows multiple NULLs in a unique index (the brief window
 * between INSERT and the model's provisional-invcode `created` hook).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_notes', function (Blueprint $t): void {
            $t->string('invcode', 30)->nullable()->change();
            $t->unsignedBigInteger('code')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Reverting to NOT NULL requires that no provisional (unissued) rows exist;
        // this is a best-effort restore of the original column shape.
        Schema::table('delivery_notes', function (Blueprint $t): void {
            $t->string('invcode', 15)->nullable(false)->change();
            $t->unsignedBigInteger('code')->nullable(false)->change();
        });
    }
};
