<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Expenses (έξοδα / εισροές) — the doc-header twin of `invoices`, for the
 * Έξοδα phase. A παραστατικό a SUPPLIER filed against us, pulled from myDATA
 * `RequestDocs` (or keyed manually). Net-new: the legacy app had no expense
 * concept, so there is NO legacy_id here.
 *
 * Like `invoices`, the myDATA state columns (`mydata_state`/`cancelled_by_mark`
 * /urls) are a denormalised cache of the latest AADE state; the byte-exact
 * audit trail lives in `expense_marks`. Money follows the house decimals.
 *
 * Per the phase plan we go PER-LINE (see `expense_lines`); this header carries
 * only the doc-level totals + identity.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();

            // myDATA identity of the supplier's document.
            $t->string('mydata_mark', 50)->nullable();       // the AADE MARK
            $t->string('uid', 80)->nullable();
            $t->string('authentication_code', 120)->nullable();

            // Header (from RequestDocs <invoiceHeader>).
            $t->string('invoice_type', 10)->nullable();       // e.g. 1.1, 2.1, 9.3
            $t->string('series', 60)->nullable();
            $t->string('aa', 60)->nullable();
            $t->date('issue_date')->nullable();
            $t->string('currency', 3)->default('EUR');

            // Issuer (supplier) snapshot — kept even when supplier_id is set,
            // so the audit survives a supplier edit/merge.
            $t->string('supplier_afm', 20)->nullable();
            $t->string('supplier_name', 191)->nullable();

            // Money (input side / ΦΠΑ εισροών).
            $t->decimal('net_total', 14, 2)->default(0);
            $t->decimal('vat_total', 14, 2)->default(0);
            $t->decimal('gross_total', 14, 2)->default(0);

            // myDATA state cache (mirror of invoices.mydata_*).
            $t->string('mydata_state', 30)->nullable();       // VALID / CANCELLED
            $t->string('cancelled_by_mark', 50)->nullable();
            $t->string('qr_url', 1500)->nullable();
            $t->string('downloading_invoice_url', 1500)->nullable();

            // Expense classification lifecycle (E5).
            $t->string('classification_state', 30)->nullable(); // null / pending / classified

            $t->string('source', 20)->default('manual');      // App\Enums\ExpenseSource
            $t->text('notes')->nullable();

            $t->timestamps();
            $t->softDeletes();

            // One local row per AADE MARK per tenant. NULL marks (manual
            // expenses with no myDATA doc yet) are allowed to repeat — both
            // MariaDB and sqlite permit multiple NULLs in a unique index.
            $t->unique(['company_id', 'mydata_mark']);
            $t->index(['company_id', 'issue_date']);
            $t->index(['company_id', 'mydata_state']);
            $t->index('supplier_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
