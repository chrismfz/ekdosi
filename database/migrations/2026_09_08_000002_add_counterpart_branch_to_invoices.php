<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `invoices.counterpart_branch` — the AADE εγκατάσταση (establishment) number of
 * the COUNTERPART this document was issued to. `0` = the party's έδρα (head
 * office), which is the case for the overwhelming majority of documents.
 *
 * The "by the book" replacement for the legacy duplicate-ΑΦΜ branch hack: a
 * customer is ONE ΑΦΜ (enforced by UNIQUE(company_id, afm_key)); which of that
 * customer's establishments received the goods/services is a per-DOCUMENT
 * attribute, not a second customer row. It rides the frozen party snapshot
 * (address1/vat_no/…) — the operator can already override the printed address per
 * invoice; this adds the one field the snapshot lacked so the FILED myDATA
 * counterpart also names the correct branch (Counterpart->setBranch).
 *
 * NOT NULL default 0 (a branch is always defined; 0 = έδρα), so every existing row
 * backfills to the truth without a data pass. The ISSUER branch stays 0 — a
 * separate axis, tracked as MYD-010.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->unsignedSmallInteger('counterpart_branch')->default(0)->after('country');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', fn (Blueprint $t) => $t->dropColumn('counterpart_branch'));
    }
};
