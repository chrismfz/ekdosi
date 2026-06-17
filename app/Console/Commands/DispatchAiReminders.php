<?php

namespace App\Console\Commands;

use App\Models\AiPendingAction;
use App\Models\Scopes\CompanyScope;
use App\Services\Assistant\AiActionExecutor;
use Illuminate\Console\Command;

/**
 * Deliver due AI «Βοηθός» reminders — the confirmed `reminder` AiPendingAction
 * rows whose `remind_at` has come and that haven't been delivered yet — as
 * Filament database notifications (the bell). Meant for the scheduler
 * (config: schedule.ai_reminders_enabled); harmless to run by hand.
 *
 *   php artisan ai:dispatch-reminders [--dry-run]
 *
 * All-tenant sweep: the global CompanyScope is dropped deliberately (the
 * confirm/deliver path re-binds each row's own tenant via the executor).
 */
class DispatchAiReminders extends Command
{
    protected $signature = 'ai:dispatch-reminders {--dry-run : Report only, deliver nothing}';

    protected $description = 'Στέλνει τις ώριμες υπενθυμίσεις του βοηθού AI ως ειδοποιήσεις (bell).';

    public function handle(AiActionExecutor $executor): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $due = AiPendingAction::query()
            ->withoutGlobalScope(CompanyScope::class) // deliberate all-tenant sweep
            ->dueReminders()
            ->with(['user', 'customer', 'company'])
            ->orderBy('remind_at')
            ->limit(500)
            ->get();

        if ($due->isEmpty()) {
            $this->line('Καμία ώριμη υπενθύμιση.');

            return self::SUCCESS;
        }

        $this->info($due->count().' υπενθυμίσεις'.($dryRun ? ' (dry-run)' : '').'.');

        foreach ($due as $action) {
            if ($dryRun) {
                $this->line("[#{$action->id}] {$action->summary}");

                continue;
            }
            $executor->deliverReminder($action);
        }

        return self::SUCCESS;
    }
}
