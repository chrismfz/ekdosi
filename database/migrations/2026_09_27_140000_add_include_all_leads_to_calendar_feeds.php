<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «Το ημερολόγιό μου»: all the company's open leads (with the operator's name),
 * not only mine — for whoever may see every lead in the panel (default ON for
 * approvers/admins when the link is created).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calendar_feeds', function (Blueprint $table) {
            $table->boolean('include_all_leads')->default(false)->after('include_leads');
        });
    }

    public function down(): void
    {
        Schema::table('calendar_feeds', fn (Blueprint $table) => $table->dropColumn('include_all_leads'));
    }
};
