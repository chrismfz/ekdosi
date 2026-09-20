<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «Προτιμολόγιο»: a draft the operator has finalised and offered to the customer.
 *
 * Deliberately a FLAG on the draft, not a fourth `local_status`. `local_status` is
 * load-bearing in 206 places across 72 files (84 of them comparing to 'draft' or
 * 'active' explicitly); a new value would force a judgement call at every one of
 * them, and a single wrong call at a money site is a silent money bug. Keeping the
 * row a `draft` means all of those sites keep their existing meaning untouched —
 * a proforma is still not a legal document, still outside every money total, still
 * invisible to myDATA.
 *
 * What the flag changes is only: the document stops being editable, the customer
 * can see it, and the customer can pay it (or point existing credit at it).
 *
 * Why it matters (recurring services): renewals are staged as drafts
 * (StageServiceRenewal). Issuing them unilaterally and cancelling the ones the
 * customer no longer wants would produce a stream of ΑΚΥ/credit notes — the exact
 * pattern that draws AADE attention. Letting the customer settle the proforma
 * first, and only then issuing, keeps the cancellations from ever existing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->timestamp('offered_at')->nullable()->after('local_status');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropColumn('offered_at');
        });
    }
};
