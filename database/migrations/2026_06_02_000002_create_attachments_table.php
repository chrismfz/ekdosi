<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Συνημμένα/Έγγραφα — polymorphic file attachments on any tenant-owned record
 * (customers, invoices, …). Files live on a private disk; this row is the
 * metadata + audit (who uploaded what, when). Net-new concept, no legacy_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attachments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->morphs('attachable');                              // attachable_type + attachable_id (+ index)
            $t->string('disk')->default('local');
            $t->string('path');
            $t->string('original_name');
            $t->string('mime_type')->nullable();
            $t->unsignedBigInteger('size')->nullable();           // bytes
            $t->string('title')->nullable();                      // optional operator label
            $t->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->softDeletes();

            $t->index(['company_id', 'attachable_type', 'attachable_id'], 'attachments_company_attachable_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
    }
};
