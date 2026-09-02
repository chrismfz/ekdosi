<?php

namespace App\Console\Commands;

use App\Filament\Resources\Leads\LeadResource;
use App\Models\Company;
use App\Models\Lead;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Leads L2 — «επόμενο βήμα» reminders as Filament DATABASE notifications (the
 * bell), NO email.
 *
 *   php artisan leads:notify-due [--tenant=SLUG] [--dry-run]
 *
 * For each tenant, every OPEN lead whose `next_action_at` is due today or
 * earlier is a reminder: an assigned lead goes to its operator, an unassigned
 * one to every user of the tenant. ONE summary notification per user (count +
 * the first few names, linking to the Leads list) — meant to run daily
 * (default OFF — config/ekdosi.php → schedule.leads_notify_due_enabled).
 *
 * Read-only: never touches the lead. «Due» is `next_action_at <= end of today`
 * so a step planned for later today is in the morning digest; the list's
 * «Ληξιπρόθεσμα» tab (next_action_at < now) is the always-on surface.
 */
class NotifyDueLeads extends Command
{
    protected $signature = 'leads:notify-due {--tenant= : Limit to one company slug} {--dry-run : Report only, send nothing}';

    protected $description = 'Στέλνει ειδοποίηση (bell) για leads με επόμενο βήμα σήμερα ή ληξιπρόθεσμο — χωρίς email.';

    private const NAMES_SHOWN = 5;

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $companies = Company::query()
            ->when($this->option('tenant'), fn ($q, $slug) => $q->where('slug', $slug))
            ->get();

        if ($companies->isEmpty()) {
            if ($this->option('tenant')) {
                $this->warn('Δεν βρέθηκε tenant.');

                return self::FAILURE;
            }
            $this->info('Κανένας tenant — τίποτα να ειδοποιηθεί.');

            return self::SUCCESS;
        }

        foreach ($companies as $company) {
            $due = Lead::query()
                ->where('company_id', $company->id)
                ->open()
                ->whereNotNull('next_action_at')
                ->where('next_action_at', '<=', now()->endOfDay())
                ->orderBy('next_action_at')
                ->get(['id', 'name', 'assigned_user_id', 'next_action_at']);

            if ($due->isEmpty()) {
                $this->line("[{$company->slug}] κανένα lead με επόμενο βήμα σήμερα.");

                continue;
            }

            $users = $company->users()->get()->keyBy('id');
            // user id => the leads to remind them of. An assignee who no longer
            // belongs to the tenant is treated as «unassigned» (everyone hears).
            $perUser = [];
            foreach ($due as $lead) {
                $targets = ($lead->assigned_user_id !== null && $users->has($lead->assigned_user_id))
                    ? [$lead->assigned_user_id]
                    : $users->keys()->all();
                foreach ($targets as $uid) {
                    $perUser[$uid][] = $lead;
                }
            }

            $this->info("[{$company->slug}] {$due->count()} lead(s) με επόμενο βήμα σήμερα/ληξιπρόθεσμο → ".count($perUser).' χρήστες'.($dryRun ? ' (dry-run)' : ''));

            if ($dryRun) {
                continue;
            }

            foreach ($perUser as $uid => $leads) {
                /** @var User $user */
                $user = $users[$uid];
                $this->notify($user, $company, collect($leads));
            }
        }

        return self::SUCCESS;
    }

    /**
     * @param  Collection<int, Lead>  $leads
     */
    private function notify(User $user, Company $company, Collection $leads): void
    {
        $count = $leads->count();
        $overdue = $leads->filter(fn (Lead $l): bool => $l->next_action_at !== null && $l->next_action_at->isPast())->count();
        $names = $leads->take(self::NAMES_SHOWN)->map(fn (Lead $l): string => $l->name)->implode(', ');
        $more = $count > self::NAMES_SHOWN ? ' +'.($count - self::NAMES_SHOWN).' ακόμη' : '';

        Notification::make()
            ->title($count === 1 ? 'Επόμενο βήμα σε lead' : "Επόμενο βήμα σε {$count} leads")
            ->body($names.$more.($overdue > 0 ? " — {$overdue} ληξιπρόθεσμα." : '.'))
            ->icon('heroicon-o-funnel')
            ->color($overdue > 0 ? 'warning' : 'info')
            ->actions([
                Action::make('view')
                    ->label('Προβολή')
                    ->url(LeadResource::getUrl('index', ['activeTab' => $overdue > 0 ? 'overdue' : 'open'], tenant: $company))
                    ->markAsRead(),
            ])
            ->sendToDatabase($user);
    }
}
