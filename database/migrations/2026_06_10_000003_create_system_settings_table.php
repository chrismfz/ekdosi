<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deploy-wide typed settings store (the «Σύστημα» area, slice 3).
 *
 * A small key→value table so an operator can flip a system flag (today: the
 * `EKDOSI_SCHEDULE_*` task toggles) from the UI instead of editing env + redeploy.
 * SYSTEM-WIDE, not per-tenant (these drive the cron, which serves every company),
 * so there is no `company_id` — read/written super_admin-only via SystemSettings.
 *
 * `type` lets the reader cast back (bool/int/string/json); env/config remains the
 * DEFAULT when a key is absent, so an empty table = exactly today's behaviour.
 * `updated_by` + the activity-log entry on save are the audit trail.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->string('type', 16)->default('string'); // bool | int | string | json
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_settings');
    }
};
