<?php

namespace App\Services\Reminders;

use App\Filament\Resources\InvoiceReminders\InvoiceReminderResource;
use App\Jobs\SendInvoiceReminder;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceReminder;
use App\Models\Scopes\CompanyScope;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * The daily pass for one tenant: tidy the log, plan today's reminders, record
 * them — «προς έγκριση» (review mode: the operator sends them from the page, with
 * a bell) or «στην ουρά» (auto mode: queued for sending straight away).
 */
final class ReminderRunner
{
    /** A row stuck in «sending» this long was interrupted mid-send. */
    private const STALE_SENDING_MINUTES = 30;

    public function __construct(private readonly ReminderPlanner $planner) {}

    /**
     * @return array{planned: int, created: int, cancelled: int}
     */
    public function run(Company $company, CarbonImmutable $today, bool $dryRun = false): array
    {
        $settings = ReminderSettings::for($company);
        if (! $settings->enabled) {
            return ['planned' => 0, 'created' => 0, 'cancelled' => 0];
        }

        $cancelled = $dryRun ? 0 : $this->tidy($company, $settings);
        $planned = $this->planner->plan($company, $today);
        if ($dryRun) {
            return ['planned' => count($planned), 'created' => 0, 'cancelled' => 0];
        }

        $auto = $settings->mode === ReminderSettings::MODE_AUTO;
        $created = 0;
        foreach ($planned as $p) {
            try {
                $row = InvoiceReminder::create([
                    'company_id' => $company->getKey(),
                    'invoice_id' => $p['invoice']->getKey(),
                    'customer_id' => $p['invoice']->customer_id,
                    'stage' => $p['stage'],
                    'auto_stage' => $p['stage'],
                    'document_kind' => ReminderPlanner::kindOf($p['invoice']),
                    'due_date' => $p['due']->toDateString(),
                    'days_overdue' => $p['days'],
                    'balance' => $p['balance'],
                    'status' => $auto ? InvoiceReminder::STATUS_QUEUED : InvoiceReminder::STATUS_AWAITING,
                    'trigger' => 'auto',
                ]);
            } catch (UniqueConstraintViolationException) {
                continue;   // a parallel run recorded this stage first
            }
            $created++;

            if ($auto) {
                SendInvoiceReminder::dispatch($row->getKey());
            }
        }

        if (! $auto && $created > 0) {
            $this->notifyOperators($company, $created);
        }

        return ['planned' => count($planned), 'created' => $created, 'cancelled' => $cancelled];
    }

    /**
     * Cancel reminders still waiting for a document that no longer qualifies
     * (paid, cancelled, opted out…), and release rows interrupted mid-send so the
     * operator can decide to send them again.
     */
    private function tidy(Company $company, ReminderSettings $settings): int
    {
        $rows = fn () => InvoiceReminder::query()->withoutGlobalScope(CompanyScope::class)->where('company_id', $company->getKey());

        $rows()->where('status', InvoiceReminder::STATUS_SENDING)
            ->where('updated_at', '<', now()->subMinutes(self::STALE_SENDING_MINUTES))
            ->update(['status' => InvoiceReminder::STATUS_FAILED, 'error_message' => 'Η αποστολή διακόπηκε — έλεγξε αν έφτασε πριν τη στείλεις ξανά.', 'updated_at' => now()]);

        $cancelled = 0;
        $waiting = $rows()->whereIn('status', [InvoiceReminder::STATUS_AWAITING, InvoiceReminder::STATUS_QUEUED])->get();
        foreach ($waiting as $row) {
            $invoice = Invoice::query()->withoutGlobalScope(CompanyScope::class)
                ->where('company_id', $company->getKey())
                ->with(['customer', 'paymentMethod', 'invoiceType'])
                ->find($row->invoice_id);
            $blocker = $invoice === null ? 'Το παραστατικό δεν υπάρχει.' : $this->planner->blocker($invoice, $settings);
            if ($blocker !== null) {
                $row->forceFill(['status' => InvoiceReminder::STATUS_CANCELLED, 'reason' => $blocker])->save();
                $cancelled++;
            }
        }

        return $cancelled;
    }

    private function notifyOperators(Company $company, int $count): void
    {
        foreach ($company->users()->get() as $user) {
            if (! $user->can('viewAny', InvoiceReminder::class)) {
                continue;
            }
            Notification::make()
                ->title('Υπενθυμίσεις πληρωμής προς έγκριση')
                ->body("{$count} νέες υπενθυμίσεις περιμένουν έγκριση για αποστολή.")
                ->icon('heroicon-o-bell-alert')
                ->warning()
                ->actions([
                    Action::make('view')
                        ->label('Προβολή')
                        ->url(InvoiceReminderResource::getUrl('index', tenant: $company))
                        ->markAsRead(),
                ])
                ->sendToDatabase($user);
        }
    }
}
