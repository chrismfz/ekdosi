<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #5 dunning (Εργασίες Είσπραξης) Φάση A: lightweight per-customer collection
 * state surfaced on the «Ηλικίωση οφειλών» list — who's chasing the debt, the
 * next step + when, the last contact date, and a free note. Record-keeping only
 * (no notifications/escalation — those are Φάση Γ; per-event history is Φάση B).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->foreignId('collection_assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->date('collection_next_step_at')->nullable();
            $table->string('collection_next_step_note')->nullable();
            $table->date('collection_last_contact_at')->nullable();
            $table->text('collection_note')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('collection_assigned_to');
            $table->dropColumn([
                'collection_next_step_at',
                'collection_next_step_note',
                'collection_last_contact_at',
                'collection_note',
            ]);
        });
    }
};
