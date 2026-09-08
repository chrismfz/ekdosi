<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional link «αυτό το δελτίο αφορά την πώληση (τιμολόγιο) Χ». Powers the
 * whichever-first stock dedup: a sale moves stock ONCE — if the linked invoice
 * already moved it, the Πώληση δελτίο skips, and vice versa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_notes', function (Blueprint $t) {
            $t->foreignId('invoice_id')->nullable()->after('customer_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('delivery_notes', function (Blueprint $t) {
            $t->dropConstrainedForeignId('invoice_id');
        });
    }
};
