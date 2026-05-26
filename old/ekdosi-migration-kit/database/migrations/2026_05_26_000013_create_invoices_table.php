<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->unsignedInteger('legacy_id')->nullable();

            $t->string('invcode', 15);                 // full code: series prefix + number
            $t->unsignedBigInteger('code');            // ΑΑ (the numeric sequence within the type)
            $t->foreignId('invoice_type_id')->constrained()->restrictOnDelete();
            $t->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $t->dateTime('issued_at');                 // legacy INVDATE + INVTIME merged

            $t->foreignId('distribution_aim_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('delivery_method_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('payment_method_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('conv_invoice_id')->nullable()->constrained('invoices')->nullOnDelete();

            $t->date('delivery_date')->nullable();
            $t->decimal('header_discount', 14, 2)->default(0);  // legacy INVOICE.DISCOUNT (currency, not %)
            $t->decimal('net_total', 14, 2)->nullable();        // legacy PRICE
            $t->decimal('gross_total', 14, 2)->nullable();      // legacy PRICEWVAT
            $t->decimal('withhold_amount', 14, 2)->nullable();

            $t->boolean('mailed')->default(false);
            $t->boolean('printed')->default(false);

            // Address / party snapshot at issue time (legal: frozen, not joined live)
            $t->string('address1', 60)->nullable();
            $t->string('address2', 60)->nullable();
            $t->string('city', 60)->nullable();
            $t->string('postcode', 10)->nullable();
            $t->string('country', 60)->nullable();
            $t->string('company_name', 120)->nullable();
            $t->string('vat_no', 20)->nullable();
            $t->string('vies_vat', 20)->nullable();
            $t->string('occupation', 120)->nullable();
            $t->text('notes')->nullable();
            $t->string('email_sent', 120)->nullable();

            // myDATA cache (source of truth lives in mydata_marks; these mirror latest state)
            $t->boolean('mydata_sent')->nullable();
            $t->string('mydata_state', 30)->nullable();   // VALID / CANCELLED
            $t->string('mydata_mark', 120)->nullable();
            $t->string('mydata_url', 1500)->nullable();

            $t->timestamps();
            $t->softDeletes();

            $t->unique(['company_id', 'invcode']);
            $t->index(['company_id', 'issued_at']);
            $t->index(['company_id', 'invoice_type_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
