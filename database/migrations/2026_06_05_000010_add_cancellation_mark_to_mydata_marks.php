<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A successful myDATA CancelInvoice returns its OWN mark (the «Μοναδικός
 * Αριθμός Ακύρωσης», firebed Response::getCancellationMark()), distinct from the
 * original invoice MARK. We kept only the original MARK on the CANCEL audit row;
 * this stores the cancellation mark too, so the audit trail records the actual
 * cancellation act (not just which document it cancelled).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mydata_marks', function (Blueprint $table): void {
            $table->string('cancellation_mark', 40)->nullable()->after('mark');
        });
    }

    public function down(): void
    {
        Schema::table('mydata_marks', function (Blueprint $table): void {
            $table->dropColumn('cancellation_mark');
        });
    }
};
