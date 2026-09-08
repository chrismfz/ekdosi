<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit log of one company-backup run (Phase 4) — when, what bucket, which
 * destinations, size, ok/failed — surfaced in the Company «Αντίγραφα ασφαλείας»
 * tab with a per-row Download (local bundles).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_backup_runs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();

            $t->dateTime('started_at');
            $t->dateTime('finished_at')->nullable();
            $t->string('trigger', 20)->default('manual'); // manual / scheduled
            $t->string('bucket', 20);
            $t->string('secrets_mode', 20);
            $t->json('destinations')->nullable();          // per-destination {driver,status,message}
            $t->unsignedBigInteger('bytes')->nullable();
            $t->string('status', 20)->default('ok');       // ok / failed / partial
            $t->text('message')->nullable();
            $t->string('bundle_path', 500)->nullable();    // local artifact, for Download

            $t->timestamps();

            $t->index(['company_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_backup_runs');
    }
};
