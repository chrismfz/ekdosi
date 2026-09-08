<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PROV-019: link a reissued/replacement invoice back to the original it replaces.
 *
 * `ReissueInvoiceAsDraft` (and «Ακύρωση & επανέκδοση») creates a fresh draft copy
 * of an original that is being reversed by a credit note. Until now that copy had
 * NO reference back to the original — so nothing could tell, at filing time, that
 * a document is a replacement whose reversed original might still be standing at
 * AADE (an un-filed draft credit). This nullable self-FK is that link; it drives
 * the soft-warn on filing the replacement and the honest «αντικαθιστά το #Χ» UI.
 *
 * Nullable + nullOnDelete: a normal invoice has no origin, and deleting an
 * original must never cascade-delete its (legally significant) replacement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $t) {
            if (! Schema::hasColumn('invoices', 'reissued_from_invoice_id')) {
                $t->foreignId('reissued_from_invoice_id')
                    ->nullable()
                    ->after('credited_invoice_id')
                    ->constrained('invoices')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $t) {
            if (Schema::hasColumn('invoices', 'reissued_from_invoice_id')) {
                $t->dropConstrainedForeignId('reissued_from_invoice_id');
            }
        });
    }
};
