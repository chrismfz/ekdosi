<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Εσωτερικές σημειώσεις — polymorphic, operator-only notes on any tenant-owned
 * record (customers, invoices, …). NEVER printed on the PDF and NEVER sent to
 * AADE — distinct from `invoices.notes` (which IS printed). The "internal
 * note per παραστατικό" the operators wanted lives here. Net-new, no legacy_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->morphs('notable');                                  // notable_type + notable_id (+ index)
            $t->text('body');
            $t->boolean('is_pinned')->default(false);
            $t->foreignId('author_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->softDeletes();

            $t->index(['company_id', 'notable_type', 'notable_id'], 'notes_company_notable_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notes');
    }
};
