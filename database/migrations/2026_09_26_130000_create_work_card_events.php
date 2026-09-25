<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ΕΡΓΑΝΗ Φάση 3 — Ψηφιακή Κάρτα Εργασίας (WRKCardSE), docs/ergani/README.md.
 *
 *  - work_card_events : one row per «χτύπημα» (in/out) — the source of truth of
 *                       the movement; ergani_* is the submission cache (the full
 *                       exchange lives in ergani_submissions). NOT deletable: a
 *                       card cannot be withdrawn from ΕΡΓΑΝΗ via the API.
 *  - employees.has_work_card : only employees registered in ΕΡΓΑΝΗ «με ένδειξη
 *                       κάρτας» get submitted (else ΕΡΓΑΝΗ: «Χωρίς Ένδειξη Κάρτας»).
 *  - employees.card_pin_* : the tablet «ρολόι» (name + PIN, no phone/login) — the PIN
 *                       is stored hashed only, locked after repeated wrong tries.
 *  - companies.ergani_submit_cards : explicit opt-in (default OFF).
 *  - companies.ergani_card_requires_kiosk : only accept punches made by scanning
 *                       the office QR (proof of presence), not the plain button.
 *  - work_card_kiosk_devices : activated office tablets (per device: name, last
 *                       seen, revocable) — the /card-kiosk page needs no login.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_card_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('type', 3);                        // in | out
            $table->timestamp('occurred_at');
            $table->date('reference_date');                   // the work day (an «out» after midnight keeps its «in» day)
            $table->string('source', 10);                     // self | kiosk | admin
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note', 200)->nullable();
            $table->string('ergani_status', 15)->nullable();  // null | submitting | submitted | failed | unknown
            $table->string('ergani_env', 12)->nullable();
            $table->string('ergani_protocol', 50)->nullable();
            $table->timestamp('ergani_submitted_at')->nullable();
            $table->string('late_reason', 10)->nullable();    // f_aitiologia when submitted > 15'
            $table->text('ergani_error')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'occurred_at']);
            $table->index(['employee_id', 'occurred_at']);
            $table->index(['company_id', 'ergani_status']);
        });

        Schema::table('ergani_submissions', function (Blueprint $table) {
            $table->foreignId('work_card_event_id')->nullable()->after('leave_request_id')->constrained()->nullOnDelete();
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->boolean('has_work_card')->default(false);
            // «Ρολόι» on the office tablet: name + PIN (hash only), brute-force lock.
            $table->string('card_pin_hash')->nullable();
            $table->unsignedTinyInteger('card_pin_failures')->default(0);
            $table->timestamp('card_pin_locked_until')->nullable();
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->boolean('ergani_submit_cards')->default(false);
            $table->boolean('ergani_card_requires_kiosk')->default(false);
        });

        // Activated office tablets — one row PER DEVICE (named, listable, revocable
        // one by one). The device cookie carries a random token; only its SHA-256
        // is stored. The tablet needs no login (never an admin session on a wall).
        Schema::create('work_card_kiosk_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->char('token_hash', 64)->unique();
            $table->foreignId('activated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_seen_at')->nullable();
            $table->string('last_ip', 45)->nullable();
            $table->string('last_user_agent', 255)->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_card_kiosk_devices');
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['ergani_submit_cards', 'ergani_card_requires_kiosk']);
        });
        Schema::table('employees', fn (Blueprint $table) => $table->dropColumn(['has_work_card', 'card_pin_hash', 'card_pin_failures', 'card_pin_locked_until']));
        Schema::table('ergani_submissions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('work_card_event_id');
        });
        Schema::dropIfExists('work_card_events');
    }
};
