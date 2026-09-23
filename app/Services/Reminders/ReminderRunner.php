<?php

namespace App\Services\Reminders;

use App\Filament\Resources\InvoiceReminders\InvoiceReminderResource;
use App\Jobs\SendInvoiceReminder;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceReminder;
use App\Models\Scopes\CompanyScope;
use App\Models\User;
use App\Services\InvoiceBalance;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\PermissionRegistrar;

/**
 * The daily pass for one tenant: tidy the log, plan today's reminders, record
 * them — «προς έγκριση» (review mode: the operator sends them from the page, with
 * a bell) or «στην ουρά» (auto mode: queued for sending straight away).
 */
final class ReminderRunner
{
    /** A row stuck in «sending» (or «queued») this long was interrupted / lost. */
    private const STALE_SENDING_MINUTES = 30;

    public function __construct(
        private readonly ReminderPlanner $planner,
        private readonly InvoiceBalance $balances,
    ) {}

    /**
     * @return array{planned: int, created: int, cancelled: int}
     */
    public function run(Company $company, CarbonImmutable $today, bool $dryRun = false): array
    {
        // «From today» must mean the day they were switched on, not every new day
        // (which would never let an after-due stage fire): pin it on first run.
        if (! $dryRun && $company->reminders_enabled && $company->reminders_since === null) {
            $company->forceFill(['reminders_since' => CarbonImmutable::today()->toDateString()])->save();
        }

        $settings = ReminderSettings::for($company);
        $cancelled = $dryRun ? 0 : $this->tidy($company, $settings);
        if (! $settings->enabled) {
            // Switched off: the tidy above cancelled whatever was still waiting.
            return ['planned' => 0, 'created' => 0, 'cancelled' => $cancelled];
        }

        $planned = $this->planner->plan($company, $today);
        if ($dryRun) {
            return ['planned' => count($planned), 'created' => 0, 'cancelled' => 0];
        }

        $auto = $settings->mode === ReminderSettings::MODE_AUTO;
        // A document with a reminder mid-send waits for tomorrow — it can't be
        // superseded in flight, and the customer must not get two at once.
        $inFlight = InvoiceReminder::query()->withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->getKey())
            ->where('status', InvoiceReminder::STATUS_SENDING)
            ->pluck('invoice_id')->flip();
        $created = 0;
        foreach ($planned as $p) {
            if ($inFlight->has($p['invoice']->getKey())) {
                continue;
            }
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
            $cancelled += $this->supersede($row);

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
     * «Υπενθύμιση τώρα»: one manual reminder per chosen document, queued for
     * sending straight away (works whether or not automatic reminders are on).
     * Documents that can't be reminded (paid, no email…) are skipped with the
     * reason; one already on its way is not queued twice.
     *
     * @param  iterable<Invoice>  $invoices  the tenant's documents (caller-scoped)
     * @return array{queued: int, skipped: array<string, string>} skipped: invcode => reason
     */
    public function sendManual(Company $company, iterable $invoices, ?int $userId): array
    {
        $settings = ReminderSettings::for($company);
        $queued = 0;
        $skipped = [];

        foreach ($invoices as $invoice) {
            if ((int) $invoice->company_id !== (int) $company->getKey()) {
                continue;
            }
            $invoice->loadMissing(['customer', 'paymentMethod', 'invoiceType']);
            $balance = $this->balances->for($invoice)->balance;
            $blocker = $this->planner->blocker($invoice, $settings, $balance, manual: true);
            $pending = InvoiceReminder::query()->withoutGlobalScope(CompanyScope::class)
                ->where('invoice_id', $invoice->getKey())
                ->where('trigger', 'manual')
                ->whereIn('status', [InvoiceReminder::STATUS_QUEUED, InvoiceReminder::STATUS_SENDING])
                ->exists();
            if ($blocker !== null || $pending) {
                $skipped[(string) $invoice->invcode] = $blocker ?? 'Υπάρχει ήδη υπενθύμιση σε αποστολή.';

                continue;
            }

            $due = ReminderPlanner::dueDateOf($invoice);
            $row = InvoiceReminder::create([
                'company_id' => $company->getKey(),
                'invoice_id' => $invoice->getKey(),
                'customer_id' => $invoice->customer_id,
                'stage' => InvoiceReminder::STAGE_MANUAL,
                'auto_stage' => null,
                'document_kind' => ReminderPlanner::kindOf($invoice),
                'due_date' => $due?->toDateString(),
                'days_overdue' => $due !== null ? (int) $due->diffInDays(CarbonImmutable::today(), false) : null,
                'balance' => $balance,
                'status' => InvoiceReminder::STATUS_QUEUED,
                'trigger' => 'manual',
                'triggered_by_user_id' => $userId,
            ]);
            SendInvoiceReminder::dispatch($row->getKey());
            $queued++;
        }

        return ['queued' => $queued, 'skipped' => $skipped];
    }

    /**
     * Cancel reminders still waiting for a document that no longer qualifies
     * (paid, cancelled, opted out, reminders switched off…), and release rows
     * interrupted mid-send so the operator can decide to send them again.
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
            $blocker = $this->planner->rowBlocker($row, $invoice, $settings);
            if ($blocker !== null && $this->cancel($row, $blocker)) {
                $cancelled++;
            }
        }

        // Queued long ago but never picked up (a lost job, a restored bundle): hand
        // it to the sender again — the claim makes an extra dispatch harmless.
        $stale = $rows()->where('status', InvoiceReminder::STATUS_QUEUED)
            ->where('updated_at', '<', now()->subMinutes(self::STALE_SENDING_MINUTES))
            ->pluck('id');
        foreach ($stale as $id) {
            if ($rows()->whereKey($id)->where('status', InvoiceReminder::STATUS_QUEUED)->update(['updated_at' => now()]) > 0) {
                SendInvoiceReminder::dispatch($id);
            }
        }

        return $cancelled;
    }

    /**
     * A new stage replaces the document's earlier ones that never went out
     * (still «προς έγκριση», queued behind a stalled worker, or failed) — the
     * customer gets the CURRENT stage, never the 1st and the 2nd on the same day,
     * nor a 1st after the final.
     */
    private function supersede(InvoiceReminder $row): int
    {
        return InvoiceReminder::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->where('invoice_id', $row->invoice_id)
            ->where('trigger', 'auto')   // an operator's manual reminder is theirs to handle
            ->whereKeyNot($row->getKey())
            ->whereIn('status', [InvoiceReminder::STATUS_AWAITING, InvoiceReminder::STATUS_QUEUED, InvoiceReminder::STATUS_FAILED])
            ->update([
                'status' => InvoiceReminder::STATUS_CANCELLED,
                'reason' => 'Αντικαταστάθηκε από «'.(InvoiceReminder::STAGE_LABELS[$row->stage] ?? $row->stage).'».',
                'updated_at' => now(),
            ]);
    }

    /**
     * Cancel only if still waiting (an operator may have sent it meanwhile); the
     * stage is released only if it was never attempted (see InvoiceReminder).
     */
    private function cancel(InvoiceReminder $row, string $reason): bool
    {
        return InvoiceReminder::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->whereKey($row->getKey())
            ->whereIn('status', [InvoiceReminder::STATUS_AWAITING, InvoiceReminder::STATUS_QUEUED])
            ->update([
                'status' => InvoiceReminder::STATUS_CANCELLED,
                'auto_stage' => DB::raw('CASE WHEN attempts = 0 THEN NULL ELSE auto_stage END'),
                'reason' => $reason,
                'updated_at' => now(),
            ]) > 0;
    }

    private function notifyOperators(Company $company, int $count): void
    {
        // Teams-mode permissions are per tenant: a scheduled (CLI) run has no
        // team context, and without it nobody «may» see the page → no bell.
        $registrar = app(PermissionRegistrar::class);
        $previousTeam = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId($company->getKey());

        try {
            $users = $company->users()->get()
                ->filter(fn (User $u): bool => Gate::forUser($u)->allows('viewAny', InvoiceReminder::class));
        } finally {
            $registrar->setPermissionsTeamId($previousTeam);
        }

        foreach ($users as $user) {
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
