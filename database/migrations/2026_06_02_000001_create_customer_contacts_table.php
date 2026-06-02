<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Επαφές ανά πελάτη — multiple people behind one customer (λογιστήριο,
 * τεχνικός, υπεύθυνος…). Net-new concept: no legacy source, so no legacy_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_contacts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->string('role')->nullable();        // ρόλος/τμήμα (free text: Λογιστήριο, Τεχνικός…)
            $t->string('email')->nullable();
            $t->string('phone')->nullable();
            $t->boolean('is_primary')->default(false);
            $t->text('notes')->nullable();
            $t->unsignedInteger('sort_order')->default(0);
            $t->timestamps();
            $t->softDeletes();

            $t->index(['company_id', 'customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_contacts');
    }
};
