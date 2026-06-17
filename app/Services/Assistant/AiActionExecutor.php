<?php

namespace App\Services\Assistant;

use App\Filament\Resources\Customers\CustomerResource;
use App\Mail\CustomerStatementMail;
use App\Models\AiPendingAction;
use App\Models\User;
use App\Services\CustomerLedger\CustomerStatementPdfRenderer;
use App\Services\TenantMailerFactory;
use App\Support\Tenancy\CompanyContext;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

/**
 * Performs an AI «Βοηθός» WRITE action ONLY after the operator confirms it. The
 * side effect (send the statement email, arm/deliver the reminder) runs here,
 * not in the tool — re-validating everything from the staged {@see
 * AiPendingAction} row (never from client input) and inside the row's own
 * tenant ({@see CompanyContext::actAs}). A confirmed action is auditable; a
 * cancelled one never fires.
 */
class AiActionExecutor
{
    /**
     * Confirm + execute a pending action. Returns a Greek result line for the
     * chat transcript. Idempotent on a non-pending row (returns its state).
     */
    public function confirm(AiPendingAction $action, User $user): string
    {
        if (! $action->isPending()) {
            return 'Η ενέργεια δεν εκκρεμεί πλέον.';
        }

        return app(CompanyContext::class)->actAs($action->company, function () use ($action, $user): string {
            return match ($action->type) {
                AiPendingAction::TYPE_SEND_STATEMENT => $this->confirmSendStatement($action, $user),
                AiPendingAction::TYPE_REMINDER => $this->confirmReminder($action),
                default => $this->fail($action, 'Άγνωστος τύπος ενέργειας.'),
            };
        });
    }

    public function cancel(AiPendingAction $action): string
    {
        if (! $action->isPending()) {
            return 'Η ενέργεια δεν εκκρεμεί πλέον.';
        }

        $action->forceFill([
            'status' => AiPendingAction::STATUS_CANCELLED,
            'cancelled_at' => now(),
        ])->save();

        return 'Ακυρώθηκε.';
    }

    private function confirmSendStatement(AiPendingAction $action, User $user): string
    {
        // Re-check the permission at confirm time (defence in depth — the confirm
        // button is also visibility-gated, but never trust the client).
        if (! Gate::forUser($user)->allows('View:Customer')) {
            return $this->fail($action, 'Δεν έχετε πρόσβαση.');
        }

        $customer = $action->customer;
        if ($customer === null) {
            return $this->fail($action, 'Ο πελάτης δεν βρέθηκε.');
        }

        $recipients = array_values(array_filter(
            (array) ($action->payload['recipients'] ?? []),
            fn ($a): bool => is_string($a) && filter_var($a, FILTER_VALIDATE_EMAIL),
        ));
        if ($recipients === []) {
            return $this->fail($action, 'Δεν υπάρχει έγκυρος παραλήπτης.');
        }

        try {
            $bytes = app(CustomerStatementPdfRenderer::class)->render($customer);
            $mail = new CustomerStatementMail(customer: $customer, pdfBytes: $bytes);
            app(TenantMailerFactory::class)->for($action->company)->to($recipients)->send($mail);
        } catch (\Throwable $e) {
            Log::error('AI statement send failed', ['action_id' => $action->id, 'error' => $e->getMessage()]);

            return $this->fail($action, 'Η αποστολή απέτυχε. Ελέγξτε τις ρυθμίσεις email.');
        }

        $result = 'Στάλθηκε στο '.implode(', ', $recipients);
        $action->forceFill([
            'status' => AiPendingAction::STATUS_CONFIRMED,
            'confirmed_at' => now(),
            'result' => $result,
        ])->save();

        return $result;
    }

    private function confirmReminder(AiPendingAction $action): string
    {
        $action->forceFill([
            'status' => AiPendingAction::STATUS_CONFIRMED,
            'confirmed_at' => now(),
        ])->save();

        // Due now → deliver immediately; otherwise the scheduler picks it up.
        if ($action->remind_at !== null && $action->remind_at->isPast()) {
            $this->deliverReminder($action);

            return 'Η υπενθύμιση καταχωρίστηκε και στάλθηκε.';
        }

        return 'Η υπενθύμιση ορίστηκε για '.optional($action->remind_at)->format('d/m/Y H:i').'.';
    }

    /**
     * Deliver a confirmed reminder as a Filament database notification (the bell)
     * to its owner, and stamp it delivered. Shared by the immediate path and the
     * `ai:dispatch-reminders` sweeper.
     */
    public function deliverReminder(AiPendingAction $action): void
    {
        $user = $action->user;
        if ($user === null) {
            // Orphaned owner — mark delivered so the sweeper doesn't loop on it.
            $action->forceFill(['delivered_at' => now()])->save();

            return;
        }

        $note = (string) ($action->payload['note'] ?? $action->summary);

        $notification = Notification::make()
            ->title('Υπενθύμιση')
            ->body($note)
            ->icon('heroicon-o-bell-alert')
            ->info();

        if ($action->customer !== null) {
            $notification->actions([
                Action::make('kartela')
                    ->label('Καρτέλα')
                    ->url(CustomerResource::getUrl('ledger', ['record' => $action->customer_id], tenant: $action->company))
                    ->markAsRead(),
            ]);
        }

        $notification->sendToDatabase($user);

        $action->forceFill(['delivered_at' => now()])->save();
    }

    private function fail(AiPendingAction $action, string $reason): string
    {
        $action->forceFill([
            'status' => AiPendingAction::STATUS_FAILED,
            'result' => $reason,
        ])->save();

        return $reason;
    }
}
