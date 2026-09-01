<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Χρονολόγιο lead — one row per event («πήραμε τηλέφωνο», «στείλαμε email»,
 * «ραντεβού», σημείωση, αυτόματη αλλαγή κατάστασης). Typed rows (not the
 * free-form `notes` table) so the per-operator activity report can count them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_activities', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $t->string('type', 20);                 // call / email / meeting / note / status_change / quote / converted
            $t->string('direction', 10)->nullable(); // outbound / inbound
            $t->string('outcome', 30)->nullable();   // answered / no_answer / … (per type)
            $t->dateTime('happened_at');
            $t->text('body')->nullable();
            $t->json('meta')->nullable();            // {from,to} for status_change, {quote_id}, {customer_id}…
            $t->timestamps();

            $t->index(['company_id', 'lead_id', 'happened_at']);
            $t->index(['company_id', 'user_id', 'happened_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_activities');
    }
};
