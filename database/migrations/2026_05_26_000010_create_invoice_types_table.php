<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Invoice types are per-company because the running counter (invcount) must be per tenant.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_types', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('code', 6);                         // legacy INVTYPE_ID, e.g. the series prefix
            $t->string('name', 120);
            $t->unsignedBigInteger('invcount')->default(1); // next sequence value (ΑΑ). Increment under lock.
            $t->boolean('show_on_menu')->default(true);
            $t->boolean('is_credit')->default(false);
            $t->boolean('is_return')->default(false);
            // myDATA mapping (firebed/aade-mydata enums)
            $t->string('mydata_type', 5)->nullable();
            $t->string('mydata_income_class', 30)->nullable();
            $t->string('mydata_income_class_category', 30)->nullable();
            // default associations
            $t->foreignId('distribution_aim_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('delivery_method_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('payment_method_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('default_customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $t->timestamps();
            $t->softDeletes();
            $t->unique(['company_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_types');
    }
};
