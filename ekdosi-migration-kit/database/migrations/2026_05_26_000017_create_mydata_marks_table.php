<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Legal audit trail of every myDATA submission. Keep it whole.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mydata_marks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->unsignedInteger('legacy_id')->nullable();
            $t->foreignId('invoice_id')->nullable()->constrained()->cascadeOnDelete();
            $t->string('mark', 50);
            $t->string('mydata_action', 30)->nullable();  // INSERT / CANCEL
            $t->string('invoice_url', 1500)->nullable();  // QR url extracted from response
            $t->mediumText('request')->nullable();        // full submitted XML
            $t->mediumText('response')->nullable();       // full AADE response XML
            $t->date('mark_date')->nullable();
            $t->timestamp('mark_time')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'legacy_id']);
            $t->index('invoice_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mydata_marks');
    }
};
