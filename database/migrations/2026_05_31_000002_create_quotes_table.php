<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    /**
     * Quotes (Προσφορές) — a sales offer that LOOKS like an invoice but is
     * NOT a legal document: never myDATA-filed, never counted in money /
     * καρτέλα / ΦΠΑ. It lives in its OWN table precisely so it can never leak
     * into App\Support\InvoiceScope::live() or the balance/dashboard
     * aggregations (those query `invoices` unconditionally). See
     * docs/services-quotes-roadmap.md.
     *
     * The party-snapshot columns deliberately mirror `invoices` (same names)
     * so "Μετατροπή σε Παραστατικό" is a direct field copy and a future quote
     * PDF can reuse the invoice template.
     */
    public function up(): void
    {
        Schema::create('quotes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code')->nullable();        // ΠΡ-{n}, allocated on create
            $table->string('legacy_id')->nullable();
            $table->string('subject')->nullable();     // Θέμα προσφοράς
            $table->string('status')->default('draft'); // App\Enums\QuoteStatus

            $table->date('issued_at')->nullable();      // πότε την κάναμε
            $table->date('valid_until')->nullable();    // πότε λήγει η προσφορά
            // Αν βγει υπηρεσία: πότε λήγει η υπηρεσία (χειροκίνητη παρακολούθηση
            // ανανέωσης — μέχρι να φτιαχτεί το recurring engine).
            $table->date('service_until')->nullable();

            // Bill-to snapshot — SAME column names as invoices (self-contained PDF
            // + direct copy on convert). Auto-filled from the customer on select.
            $table->string('company_name')->nullable();
            $table->string('vat_no')->nullable();
            $table->string('vies_vat')->nullable();
            $table->string('occupation')->nullable();
            $table->string('address1')->nullable();
            $table->string('address2')->nullable();
            $table->string('city')->nullable();
            $table->string('postcode')->nullable();
            $table->string('country')->nullable()->default('GR');

            $table->decimal('header_discount_percent', 5, 2)->default(0);
            $table->decimal('net_total', 14, 2)->default(0);
            $table->decimal('vat_total', 14, 2)->default(0);
            $table->decimal('gross_total', 14, 2)->default(0);

            $table->text('proposal_text')->nullable();   // εμφανίζεται στην κορυφή
            $table->text('customer_notes')->nullable();   // υποσέλιδο προς πελάτη
            $table->text('admin_notes')->nullable();      // ιδιωτικές σημειώσεις

            // Provenance: the draft invoice produced by «Μετατροπή σε Παραστατικό».
            // The reverse direction (invoice → quote) is read via the
            // Invoice::convertedFromQuote() HasOne on this column — no column on
            // `invoices` needed, so the legal table stays untouched.
            $table->foreignId('converted_invoice_id')->nullable()
                ->constrained('invoices')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'valid_until']);
            $table->index(['company_id', 'service_until']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotes');
    }
};
