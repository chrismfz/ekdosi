<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer satisfaction rating on a closed ticket (Πυλώνας E, Phase 4 —
 * feedback-on-close). Filled from the portal when the ticket is Closed AND its
 * department has `feedback_on_close` on. 1–5 scale + an optional comment; the
 * columns stay null until the customer rates.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $t) {
            $t->unsignedTinyInteger('rating')->nullable()->after('closed_at'); // 1..5
            $t->text('rating_comment')->nullable()->after('rating');
            $t->timestamp('rated_at')->nullable()->after('rating_comment');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $t) {
            $t->dropColumn(['rating', 'rating_comment', 'rated_at']);
        });
    }
};
