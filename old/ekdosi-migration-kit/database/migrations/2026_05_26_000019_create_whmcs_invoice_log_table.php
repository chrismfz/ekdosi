<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Replaces legacy AUTO_INVOICE_LOG. The WHMCS bridge link itself lives on customers.whmcs_client_id.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whmcs_invoice_log', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->unsignedInteger('legacy_id')->nullable();
            $t->unsignedBigInteger('whmcs_invoice_id')->nullable()->index();  // legacy CS_INVID
            $t->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $t->text('message')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'legacy_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whmcs_invoice_log');
    }
};
