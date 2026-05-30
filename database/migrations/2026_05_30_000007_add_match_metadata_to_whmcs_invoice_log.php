<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The historical WHMCS#→ekdosi-ΤΠΥ matcher (whmcs:match-historical) writes
 * recovered links into whmcs_invoice_log.invoice_id. Because the link is
 * INFERRED (no stored mapping survived the legacy import — verified NULL/empty
 * in prod), we record HOW and HOW CONFIDENTLY each link was made, so an
 * operator can audit / trust / re-run:
 *
 *   - match_method:     'line_text' | 'amount_date' | 'manual' | null
 *   - match_confidence: 'high' | 'medium' | null
 *   - matched_at:       when the matcher wrote it
 *
 * Also adds a unique on (company_id, whmcs_invoice_id) so the matcher can
 * upsert idempotently keyed by the WHMCS invoice — the existing unique is on
 * (company_id, legacy_id), which is NULL for matcher-created rows (no legacy
 * AUTO_INVOICE_LOG source) and so can't dedupe them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whmcs_invoice_log', function (Blueprint $t): void {
            $t->string('match_method', 20)->nullable()->after('invoice_id');
            $t->string('match_confidence', 10)->nullable()->after('match_method');
            $t->timestamp('matched_at')->nullable()->after('match_confidence');
            // Idempotency key for matcher-created rows. legacy_id is NULL for
            // these, so the existing (company_id, legacy_id) unique can't hold.
            $t->unique(['company_id', 'whmcs_invoice_id'], 'wil_company_whmcs_invoice_unique');
        });
    }

    public function down(): void
    {
        Schema::table('whmcs_invoice_log', function (Blueprint $t): void {
            $t->dropUnique('wil_company_whmcs_invoice_unique');
            $t->dropColumn(['match_method', 'match_confidence', 'matched_at']);
        });
    }
};
