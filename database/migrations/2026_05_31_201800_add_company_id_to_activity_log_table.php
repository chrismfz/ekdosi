<?php

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-scope the activity log so a per-tenant feed can filter cheaply.
 * `company_id` is stamped on write by App\Models\Activity (from the subject);
 * this also backfills any rows already logged (portable, per subject type).
 */
return new class extends Migration
{
    /** @var array<class-string, string> subject model → its table */
    private array $subjects = [
        Invoice::class => 'invoices',
        Customer::class => 'customers',
        Payment::class => 'payments',
    ];

    public function up(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            $table->unsignedBigInteger('company_id')->nullable()->after('id')->index();
        });

        // Backfill existing rows from their subject's company_id. Done in PHP
        // (no correlated UPDATE…JOIN) so it runs identically on MariaDB + sqlite.
        foreach ($this->subjects as $type => $table) {
            DB::table('activity_log')
                ->where('subject_type', $type)
                ->whereNull('company_id')
                ->whereNotNull('subject_id')
                ->orderBy('id')
                ->chunkById(500, function ($rows) use ($table) {
                    $companyBySubject = DB::table($table)
                        ->whereIn('id', $rows->pluck('subject_id')->unique())
                        ->pluck('company_id', 'id');

                    foreach ($rows as $row) {
                        $companyId = $companyBySubject[$row->subject_id] ?? null;
                        if ($companyId !== null) {
                            DB::table('activity_log')->where('id', $row->id)->update(['company_id' => $companyId]);
                        }
                    }
                });
        }
    }

    public function down(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            $table->dropColumn('company_id');
        });
    }
};
