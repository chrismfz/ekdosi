<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Προσωπικό / Άδειες — Φάση 1 (docs/ergani/README.md). The ekdosi-side source of
 * truth for WHO is off WHEN, replacing the shared Google Calendar (which let an
 * approved leave never reach the accountant / ΕΡΓΑΝΗ).
 *
 *  - employees        : the staff roster (ΑΦΜ + name exactly as ΕΡΓΑΝΗ wants them),
 *                       optionally linked to a panel user (self-service, role `employee`).
 *  - leave_requests   : one row per leave (from–to, ΕΡΓΑΝΗ leave code), with the
 *                       pending → approved/rejected/cancelled lifecycle. The `ergani_*`
 *                       columns are reserved for the later WTOLeave submission.
 *  - company_holidays : the tenant's EXTRA non-working days (πολιούχος, απελευθέρωση,
 *                       office closures) on top of the national ones in code
 *                       (App\Support\Hr\GreekHolidays) — no official source exists.
 *  - companies.leave_notify_email : the accountant's address, emailed on every
 *                       approval/cancellation so the leave is declared once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('afm', 9)->nullable();
            $table->string('last_name', 100);
            $table->string('first_name', 100);
            $table->string('email', 191)->nullable();
            $table->unsignedTinyInteger('ergani_branch')->default(0);   // ΕΡΓΑΝΗ Α/Α παραρτήματος
            $table->unsignedTinyInteger('annual_leave_days')->default(20);
            $table->date('hired_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'afm']);
            $table->unique(['company_id', 'user_id']);
            $table->index(['company_id', 'is_active']);
        });

        Schema::create('leave_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('type', 10);                  // ΕΡΓΑΝΗ code, e.g. ΑΔΚΑΝ
            $table->date('starts_on');
            $table->date('ends_on');
            $table->unsignedSmallInteger('days');        // working days (editable)
            $table->string('status', 20)->default('pending');
            $table->text('reason')->nullable();          // employee's note
            $table->text('decision_note')->nullable();   // approver's note (e.g. rejection reason)
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('accountant_notified_at')->nullable();
            // What the accountant still has to be TOLD (approved|cancelled), null =
            // nothing owed. Cleared by a successful email → a failed send stays
            // visible and re-sendable instead of hiding behind an old timestamp.
            $table->string('accountant_owed_event', 10)->nullable();
            $table->string('ergani_protocol', 50)->nullable();
            $table->timestamp('ergani_submitted_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'starts_on', 'ends_on']);
            $table->index(['employee_id', 'starts_on']);
        });

        Schema::create('company_holidays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('rule', 10);                          // fixed | easter | once
            $table->unsignedTinyInteger('month')->nullable();    // fixed
            $table->unsignedTinyInteger('day')->nullable();      // fixed
            $table->smallInteger('easter_offset')->nullable();   // easter (days from Orthodox Easter Sunday)
            $table->date('date')->nullable();                    // once
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['company_id', 'is_active']);
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->string('leave_notify_email', 191)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('leave_notify_email');
        });
        Schema::dropIfExists('company_holidays');
        Schema::dropIfExists('leave_requests');
        Schema::dropIfExists('employees');
    }
};
