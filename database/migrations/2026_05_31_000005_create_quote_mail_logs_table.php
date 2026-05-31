<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    /**
     * Outbound quote-email send log — one row per attempt (queued / sending /
     * sent / failed). Twin of invoice_mail_log so the Προσφορές screen shows
     * the same delivery history operators already know from invoices.
     * (The actual send job is a follow-up PR; the schema lands now so the
     * relation/history wiring is ready.)
     */
    public function up(): void
    {
        Schema::create('quote_mail_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('quote_id')->constrained()->cascadeOnDelete();
            $table->string('recipient');
            $table->json('cc_list')->nullable();
            $table->json('bcc_list')->nullable();
            $table->string('from_address')->nullable();
            $table->string('subject')->nullable();
            $table->string('trigger')->default('manual'); // 'auto' | 'manual'
            $table->string('status')->default('queued');  // queued|sending|sent|failed
            $table->text('error_message')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->foreignId('triggered_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quote_mail_logs');
    }
};
