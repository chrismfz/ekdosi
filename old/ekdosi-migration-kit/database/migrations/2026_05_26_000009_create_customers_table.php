<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->unsignedInteger('legacy_id')->nullable();
            $t->string('type', 60)->nullable();
            $t->string('afm', 20)->nullable();
            $t->string('name', 191);
            $t->string('address1', 60)->nullable();
            $t->string('address2', 60)->nullable();
            $t->string('city', 60)->nullable();
            $t->string('postcode', 10)->nullable();
            $t->string('phone1', 30)->nullable();
            $t->string('phone2', 30)->nullable();
            $t->string('fax', 30)->nullable();
            $t->string('occupation', 120)->nullable();
            $t->string('tax_office', 60)->nullable();
            $t->text('details')->nullable();
            $t->decimal('discount', 5, 2)->default(0);
            $t->string('email', 120)->nullable();
            $t->string('secondary_email', 120)->nullable();
            $t->string('country', 60)->nullable();
            $t->string('vat_vies', 30)->nullable();
            $t->integer('withhold_tax')->nullable();
            $t->unsignedInteger('sort_order')->nullable();        // legacy "ORDER" (reserved word)
            $t->unsignedInteger('alt_customer_legacy_id')->nullable(); // legacy ALT_CUSTID, resolved post-import if used
            $t->foreignId('payment_method_id')->nullable()->constrained()->nullOnDelete();
            $t->unsignedBigInteger('whmcs_client_id')->nullable()->index();  // WHMCS bridge link
            $t->timestamps();
            $t->softDeletes();
            $t->unique(['company_id', 'legacy_id']);
            $t->index(['company_id', 'afm']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
