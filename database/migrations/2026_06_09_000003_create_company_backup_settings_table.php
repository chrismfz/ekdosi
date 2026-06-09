<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-company automated-backup policy (Phase 4). One row per company: cadence,
 * what to back up (bucket), how to seal secrets, retention, and the list of
 * destinations to fan out to. Distinct from spatie/laravel-backup (whole-DB) —
 * this is the tenant-scoped pipeline driven by CompanyExporter.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_backup_settings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();

            $t->boolean('enabled')->default(false);
            $t->string('frequency', 10)->default('off');   // off / daily / weekly / monthly
            $t->string('run_at_time', 5)->default('02:00'); // HH:MM (server time)
            $t->string('bucket', 20)->default('settings_setup'); // settings / settings_setup / full
            $t->string('secrets_mode', 20)->default('passphrase'); // passphrase / raw
            $t->text('passphrase')->nullable();             // encrypted (Laravel cast)

            $t->unsignedInteger('retention_keep')->default(7); // keep last N bundles per destination
            $t->unsignedInteger('retention_days')->nullable(); // …and/or drop older than N days

            $t->json('destinations')->nullable();           // [{driver:'local'}, {driver:'sftp', config:{…}}]

            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_backup_settings');
    }
};
