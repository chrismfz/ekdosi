<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    /**
     * Per-company quote-numbering counter. Quotes get their OWN sequence — they
     * must NEVER touch invoice_types.invcount (the legal ΑΑ continuity counter
     * owned by App\Services\InvoiceNumberer).
     */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->unsignedBigInteger('quote_counter')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('quote_counter');
        });
    }
};
