<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-tenant bank accounts (τραπεζικοί λογαριασμοί) — a simple lookup, the twin
 * of `payment_methods`. Used to tag a payment with the account the money landed
 * in («σε ποιον λογαριασμό κατατέθηκε») and to print the deposit account on an
 * invoice when the customer pays by transfer/έμβασμα. No money logic — purely
 * informational. `is_active` hides retired accounts from the pickers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_accounts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->unsignedInteger('legacy_id')->nullable();
            $t->string('bank_name', 120)->nullable();   // Τράπεζα (π.χ. Εθνική, Πειραιώς)
            $t->string('iban', 40)->nullable();
            $t->string('account_name', 160)->nullable(); // δικαιούχος / περιγραφή
            $t->string('swift', 20)->nullable();
            $t->boolean('is_active')->default(true);
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->unique(['company_id', 'legacy_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_accounts');
    }
};
