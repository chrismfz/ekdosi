<?php

namespace App\Console\Commands;

use App\Models\InvoiceMailLog;
use App\Models\Scopes\CompanyScope;
use Illuminate\Console\Command;

/**
 * Recover mail-log rows left stuck by a crashed/killed queue worker.
 *
 * SendInvoiceEmail creates a row in 'queued', transitions it to
 * 'sending' → 'sent'/'failed' inline, and its failed() hook reconciles
 * to 'failed' on retry exhaustion. But a `kill -9` (or a DI-resolution
 * fault that escapes handle()'s catch) leaves a row stuck on 'queued' /
 * 'sending' forever. This sweep flips those to 'failed' once they're
 * older than the threshold, so the operator's send-log doesn't show a
 * permanent phantom "in progress" row.
 *
 * Read-mostly safety: only touches rows in the two non-terminal states
 * and only past the age cutoff. 'sent' / 'failed' rows are never altered.
 *
 * Usage:
 *   php artisan mail-log:sweep-orphans                 # threshold from config
 *   php artisan mail-log:sweep-orphans --minutes=30
 *   php artisan mail-log:sweep-orphans --dry-run
 */
class SweepOrphanMailLogs extends Command
{
    protected $signature = 'mail-log:sweep-orphans
        {--minutes= : Age threshold in minutes (default: config ekdosi.schedule.mail_sweep_threshold_minutes)}
        {--dry-run : Report what would change without writing}';

    protected $description = 'Flip mail-log rows stuck in queued/sending past the age threshold to failed (worker-crash recovery).';

    public function handle(): int
    {
        $minutes = (int) ($this->option('minutes')
            ?? config('ekdosi.schedule.mail_sweep_threshold_minutes', 15));

        if ($minutes < 1) {
            $this->error('--minutes must be >= 1.');

            return self::FAILURE;
        }

        $cutoff = now()->subMinutes($minutes);

        // Deliberately ALL-TENANT: a crashed worker can leave stuck rows for any
        // company, and this runs from the scheduler with no ambient
        // CompanyContext. Declare that intent with an explicit withoutGlobalScope
        // (a no-op today, but self-documenting + immune to a future strict tenant
        // mode that would otherwise refuse an unscoped tenant query).
        $query = InvoiceMailLog::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->whereIn('status', ['queued', 'sending'])
            ->where('queued_at', '<', $cutoff);

        $count = (clone $query)->count();

        if ($count === 0) {
            $this->info("No orphaned mail-log rows older than {$minutes}m.");

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->warn("[dry-run] {$count} stuck row(s) older than {$minutes}m would be marked failed.");

            return self::SUCCESS;
        }

        $query->update([
            'status' => 'failed',
            'failed_at' => now(),
            'error_message' => "Reconciler: presumed worker crash (stuck > {$minutes}m).",
        ]);

        $this->info("Marked {$count} orphaned mail-log row(s) as failed (stuck > {$minutes}m).");

        return self::SUCCESS;
    }
}
