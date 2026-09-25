<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ΕΡΓΑΝΗ Φάση 2 — leave submission (WTOLeave) on approval, withdrawal on
 * revocation (docs/ergani/README.md).
 *
 *  - ergani_submissions : APPEND-ONLY audit of every ΕΡΓΑΝΗ call (full request +
 *                         response, environment, protocol) — the source of truth,
 *                         like mydata_marks; leave_requests.ergani_* is its cache.
 *  - leave_requests     : ergani_status (null = not applicable | submitted | failed |
 *                         cancelled | cancel_failed), ergani_env, ergani_error; the
 *                         Phase-1 ergani_protocol/ergani_submitted_at get filled.
 *  - companies.ergani_submit_leaves : the explicit opt-in (default OFF) — creds
 *                         alone never start submitting.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ergani_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('leave_request_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('document', 20);                 // WTOLeave | …
            $table->string('action', 10);                   // submit | cancel
            $table->string('environment', 12);              // trial | production
            $table->boolean('ok');
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('protocol', 50)->nullable();
            $table->string('ergani_id', 50)->nullable();
            $table->string('submit_date', 30)->nullable();  // as ΕΡΓΑΝΗ returned it
            $table->text('message')->nullable();
            $table->json('request')->nullable();
            $table->longText('response')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'created_at']);
            $table->index('leave_request_id');
        });

        Schema::table('leave_requests', function (Blueprint $table) {
            $table->string('ergani_status', 15)->nullable();
            $table->string('ergani_env', 12)->nullable();
            $table->text('ergani_error')->nullable();
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->boolean('ergani_submit_leaves')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('companies', fn (Blueprint $table) => $table->dropColumn('ergani_submit_leaves'));
        Schema::table('leave_requests', fn (Blueprint $table) => $table->dropColumn(['ergani_status', 'ergani_env', 'ergani_error']));
        Schema::dropIfExists('ergani_submissions');
    }
};
