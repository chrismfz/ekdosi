<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-scoped tags + a polymorphic pivot, for operator-defined labels on
 * customers / suppliers / products / invoices (συχνός, χονδρική, VIP,
 * κακοπληρωτής, hardware…). Custom (not spatie/laravel-tags) so company_id
 * scoping is first-class, matching the project's surrogate-PK + company_id
 * discipline.
 *
 * `is_pinned` promotes a tag to a fast-filter TAB (Έξοδα-style) on the lists
 * where it's used; every tag is always available as a multi-select filter.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            // Optional Filament colour name/hex for the badge (null = default).
            $table->string('color', 32)->nullable();
            // Pinned tags surface as a fast-filter tab on lists that use them.
            $table->boolean('is_pinned')->default(false);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            // One vocabulary per tenant — no duplicate names.
            $table->unique(['company_id', 'name']);
            $table->index(['company_id', 'is_pinned']);
        });

        Schema::create('taggables', function (Blueprint $table) {
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->morphs('taggable');
            // A tag is attached at most once to a given record.
            $table->unique(['tag_id', 'taggable_id', 'taggable_type'], 'taggables_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('taggables');
        Schema::dropIfExists('tags');
    }
};
