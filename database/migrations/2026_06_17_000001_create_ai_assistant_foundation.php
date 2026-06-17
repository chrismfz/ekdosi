<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI «Βοηθός» Phase-1 foundation: per-company governance knobs + the usage
 * meter. One global Anthropic key (env); isolation is the tool layer, billing is
 * this per-tenant token metering (see docs/ai-assistant-blueprint.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $t): void {
            // Kill-switch per tenant (default OFF — opt-in).
            $t->boolean('ai_assistant_enabled')->default(false)->after('mydata_mode');
            // Model override; null → the global default in config/ekdosi.php.
            $t->string('ai_model', 60)->nullable()->after('ai_assistant_enabled');
            // Monthly token ceiling (input+output); null → global backstop only.
            $t->unsignedBigInteger('ai_monthly_token_cap')->nullable()->after('ai_model');
            // Escape hatch: a tenant on its own Anthropic account (encrypted at
            // rest via the MaybeEncrypted cast). Null → the global env key.
            $t->text('ai_api_key')->nullable()->after('ai_monthly_token_cap');
        });

        // One row per Messages API turn — the billing + cap source of truth.
        Schema::create('ai_usage_log', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('conversation_id', 64)->nullable();
            $t->string('model', 60);
            $t->unsignedInteger('input_tokens')->default(0);
            $t->unsignedInteger('output_tokens')->default(0);
            $t->unsignedInteger('cache_read_tokens')->default(0);
            $t->unsignedInteger('cache_write_tokens')->default(0);
            // Our own estimate (tokens are authoritative; cost reconciles to the
            // single Anthropic invoice). decimal(10,4) → fractions of a cent.
            $t->decimal('cost_estimate', 10, 4)->default(0);
            $t->timestamp('created_at')->nullable();

            // The hot path: SUM(tokens) for a tenant in the current month.
            $t->index(['company_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage_log');
        Schema::table('companies', function (Blueprint $t): void {
            $t->dropColumn(['ai_assistant_enabled', 'ai_model', 'ai_monthly_token_cap', 'ai_api_key']);
        });
    }
};
