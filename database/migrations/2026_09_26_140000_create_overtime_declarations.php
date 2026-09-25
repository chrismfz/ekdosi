<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ΕΡΓΑΝΗ — υπερωρία (WTOOv, docs/ergani/README.md).
 *
 *  - overtime_declarations : one row per declared overtime slot (employee, day,
 *                            from–to). ergani_* caches its WTOOv submission (the
 *                            full exchange lives in ergani_submissions). NOT
 *                            deletable: ΕΡΓΑΝΗ has no API to withdraw a WTOOv, and
 *                            it must be declared BEFORE it starts (trial-verified:
 *                            a past slot → «Η υποβολή σας θεωρείται εκπρόθεσμη»).
 *  - companies.ergani_submit_overtime : explicit opt-in (default OFF).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('overtime_declarations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('work_date');
            $table->string('from_time', 5);                   // HH:MM (Athens)
            $table->string('to_time', 5);
            $table->string('note', 200)->nullable();          // internal — never sent to ΕΡΓΑΝΗ
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('ergani_status', 15)->nullable();  // null | submitting | submitted | failed | unknown | superseded
            $table->string('ergani_env', 12)->nullable();
            $table->string('ergani_protocol', 50)->nullable();
            $table->timestamp('ergani_submitted_at')->nullable();
            $table->text('ergani_error')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'work_date']);
            $table->index(['employee_id', 'work_date']);
        });

        Schema::table('ergani_submissions', function (Blueprint $table) {
            $table->foreignId('overtime_declaration_id')->nullable()->after('work_card_event_id')->constrained()->nullOnDelete();
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->boolean('ergani_submit_overtime')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('companies', fn (Blueprint $table) => $table->dropColumn('ergani_submit_overtime'));
        Schema::table('ergani_submissions', fn (Blueprint $table) => $table->dropConstrainedForeignId('overtime_declaration_id'));
        Schema::dropIfExists('overtime_declarations');
    }
};
