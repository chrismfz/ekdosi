<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payment reminders (WHMCS-style dunning): per-tenant settings on `companies`,
 * a per-customer opt-out, and the `invoice_reminders` log — one row per reminder
 * planned/sent, so the operator sees what went out and what did not.
 *
 * `auto_stage` = the stage for an automatic reminder, NULL for a manual one:
 * UNIQUE(invoice_id, auto_stage) makes «never the same automatic stage twice»
 * a database guarantee (MariaDB and sqlite both allow many NULLs under it).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->boolean('reminders_enabled')->default(false);
            $table->string('reminders_mode', 10)->default('review');   // review | auto
            $table->date('reminders_since')->nullable();                // only documents due on/after this
            $table->unsignedSmallInteger('reminder_pre_due_days')->nullable();   // days BEFORE due; null = off
            $table->unsignedSmallInteger('reminder_first_days')->nullable()->default(3);
            $table->unsignedSmallInteger('reminder_second_days')->nullable()->default(10);
            $table->unsignedSmallInteger('reminder_final_days')->nullable()->default(20);
            $table->decimal('reminder_min_balance', 14, 2)->default(1);
            $table->boolean('reminder_attach_pdf')->default(true);
            $table->json('reminder_templates')->nullable();              // {stage: {subject, body}} overrides
        });

        Schema::table('customers', function (Blueprint $table): void {
            $table->boolean('reminders_enabled')->default(true);
        });

        Schema::create('invoice_reminders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('stage', 20);                 // pre_due | first | second | final | manual
            $table->string('auto_stage', 20)->nullable(); // = stage when automatic; NULL when manual
            $table->string('document_kind', 10);         // invoice | proforma
            $table->date('due_date')->nullable();
            $table->smallInteger('days_overdue')->nullable();   // negative = before due
            $table->decimal('balance', 14, 2)->default(0);
            $table->string('status', 20);                // awaiting_approval | queued | sending | sent | failed | skipped | cancelled
            $table->string('reason', 255)->nullable();   // why skipped / cancelled
            $table->string('recipient', 191)->nullable();
            $table->string('subject', 500)->nullable();
            $table->text('error_message')->nullable();
            $table->string('trigger', 10)->default('auto');   // auto | manual
            $table->foreignId('triggered_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['invoice_id', 'auto_stage']);
            $table->index(['company_id', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_reminders');

        Schema::table('customers', function (Blueprint $table): void {
            $table->dropColumn('reminders_enabled');
        });

        Schema::table('companies', function (Blueprint $table): void {
            $table->dropColumn([
                'reminders_enabled', 'reminders_mode', 'reminders_since', 'reminder_pre_due_days',
                'reminder_first_days', 'reminder_second_days', 'reminder_final_days',
                'reminder_min_balance', 'reminder_attach_pdf', 'reminder_templates',
            ]);
        });
    }
};
