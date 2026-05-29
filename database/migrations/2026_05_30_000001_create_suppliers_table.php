<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Suppliers (προμηθευτές) — the foundation of the Έξοδα / Expenses phase.
 *
 * Net-new: the legacy C++Builder app had no supplier/expense concept, so
 * there is NO legacy_id / Firebird origin here (unlike customers). Built
 * clean from the start.
 *
 * For GR suppliers, myDATA RequestDocs returns only the issuer AFM (name &
 * address are forbidden for domestic counterparts → errors [219]/[220]), so
 * `name` is nullable and resolved via the GSIS lookup (AadeRegistryLookup) —
 * the same "Διασταύρωση ΑΦΜ" used for customers. Foreign suppliers DO carry
 * name/address from myDATA.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            $table->string('afm', 20)->nullable();
            $table->string('name', 191)->nullable();
            $table->string('tax_office', 120)->nullable();   // ΔΟΥ
            $table->string('occupation', 191)->nullable();    // Δραστηριότητα
            $table->string('address1', 191)->nullable();
            $table->string('city', 120)->nullable();
            $table->string('postcode', 20)->nullable();
            $table->string('country', 2)->default('GR');      // ISO-2; foreign suppliers ≠ GR
            $table->string('email', 191)->nullable();
            $table->string('phone1', 60)->nullable();

            $table->string('source', 20)->default('manual');  // App\Enums\SupplierSource
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // One supplier per AFM per tenant (NULLs allowed for foreign /
            // not-yet-known AFMs — MariaDB & sqlite both permit multiple NULLs
            // in a unique index).
            $table->unique(['company_id', 'afm']);
            $table->index(['company_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suppliers');
    }
};
