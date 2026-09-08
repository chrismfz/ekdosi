<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Durable scheduled-task run history (the «Σύστημα» area, slice 2).
 *
 * The cache (`HealthKeys::scheduledTask`) only ever holds the LATEST run per
 * task and is wiped by `cache:clear`. This table is the durable log: one row
 * per run, opened (status=running) by the scheduler `->before` hook and closed
 * (ok/failed + duration + summary) by `->onSuccess`/`->onFailure`. Feeds the
 * «Πρόσφατες εκτελέσεις» history on the Υγεία-συστήματος page. System-global
 * (cron-driven, no tenant) — read super_admin-only with the rest of the report.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_task_runs', function (Blueprint $table) {
            $table->id();
            $table->string('task')->index();           // the HealthKeys task key (e.g. whmcs_fetch)
            $table->string('status', 16)->default('running'); // running | ok | failed
            $table->integer('exit_code')->nullable();
            $table->unsignedBigInteger('duration_ms')->nullable();
            $table->text('summary')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            // The hot lookup: «latest run for this task» + «latest open run to close».
            $table->index(['task', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_task_runs');
    }
};
