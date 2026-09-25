<?php

namespace App\Filament\Resources\LeaveRequests;

use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\Hr\LeaveWorkflow;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use RuntimeException;

/**
 * The decision actions, shared by the table rows and the view page. Every one
 * goes through LeaveWorkflow (lock + side effects); authorization re-checked
 * per action via the policy.
 */
final class LeaveRequestActions
{
    public static function approve(): Action
    {
        return Action::make('approve')
            ->label('Έγκριση')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->visible(fn (LeaveRequest $record): bool => $record->isPending() && (auth()->user()?->can('update', $record) ?? false))
            ->authorize(fn (LeaveRequest $record): bool => auth()->user()?->can('update', $record) ?? false)
            ->modalHeading(fn (LeaveRequest $record): string => 'Έγκριση άδειας — '.$record->employee?->full_name)
            ->modalDescription(fn (LeaveRequest $record): string => $record->periodLabel().' · '.$record->type?->getLabel()
                .($record->company?->leave_notify_email
                    ? ' — θα σταλεί email στον λογιστή ('.$record->company->leave_notify_email.').'
                    : ' — ΔΕΝ έχει οριστεί email λογιστή (Εταιρεία → καρτέλα «ΕΡΓΑΝΗ»): ενημερώστε τον χειροκίνητα.'))
            ->fillForm(fn (LeaveRequest $record): array => ['days' => $record->days])
            ->schema([
                TextInput::make('days')->label('Εργάσιμες ημέρες')->numeric()->integer()->minValue(0)->maxValue(366)->required(),
                Textarea::make('note')->label('Σημείωση (προαιρετικά)')->rows(2)->maxLength(1000),
            ])
            ->action(function (LeaveRequest $record, array $data): void {
                self::run(fn (User $u) => app(LeaveWorkflow::class)->approve($record, $u, $data['note'] ?? null, (int) $data['days']), 'Η άδεια εγκρίθηκε');
                self::warnIfAccountantMissed($record->fresh());
            });
    }

    public static function reject(): Action
    {
        return Action::make('reject')
            ->label('Απόρριψη')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->visible(fn (LeaveRequest $record): bool => $record->isPending() && (auth()->user()?->can('update', $record) ?? false))
            ->authorize(fn (LeaveRequest $record): bool => auth()->user()?->can('update', $record) ?? false)
            ->schema([
                Textarea::make('note')->label('Αιτιολογία')->rows(2)->maxLength(1000)->required(),
            ])
            ->action(function (LeaveRequest $record, array $data): void {
                self::run(fn (User $u) => app(LeaveWorkflow::class)->reject($record, $u, $data['note']), 'Η άδεια απορρίφθηκε');
            });
    }

    public static function cancel(): Action
    {
        return Action::make('cancelLeave')
            ->label(fn (LeaveRequest $record): string => $record->isApproved() ? 'Ανάκληση' : 'Ακύρωση αιτήματος')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('gray')
            ->visible(fn (LeaveRequest $record): bool => auth()->user()?->can('cancel', $record) ?? false)
            ->authorize(fn (LeaveRequest $record): bool => auth()->user()?->can('cancel', $record) ?? false)
            ->requiresConfirmation()
            ->modalDescription(fn (LeaveRequest $record): string => match (true) {
                ! $record->isApproved() => 'Το αίτημα θα αποσυρθεί.',
                filled($record->company?->leave_notify_email) && $record->accountant_owed_event === null && $record->accountant_notified_at !== null => 'Η άδεια είχε εγκριθεί και ο λογιστής ενημερώθηκε — θα σταλεί email ακύρωσης.',
                default => 'Η άδεια είχε εγκριθεί αλλά ο λογιστής ΔΕΝ είχε ενημερωθεί με email — αν τον είχατε ενημερώσει χειροκίνητα, ενημερώστε τον και για την ακύρωση.',
            })
            ->action(function (LeaveRequest $record): void {
                self::run(fn (User $u) => app(LeaveWorkflow::class)->cancel($record, $u), 'Ακυρώθηκε');
                self::warnIfAccountantMissed($record->fresh());
            });
    }

    public static function resendAccountant(): Action
    {
        return Action::make('resendAccountant')
            ->label('Email στον λογιστή')
            ->icon('heroicon-o-envelope')
            ->color('warning')
            ->visible(fn (LeaveRequest $record): bool => $record->accountantOwed() !== null
                && (auth()->user()?->can('update', $record) ?? false))
            ->authorize(fn (LeaveRequest $record): bool => auth()->user()?->can('update', $record) ?? false)
            ->requiresConfirmation()
            ->modalDescription(fn (LeaveRequest $record): string => $record->accountantOwed() === 'cancelled'
                ? 'Θα σταλεί η ΑΚΥΡΩΣΗ της άδειας στον λογιστή.'
                : 'Θα σταλεί η έγκριση της άδειας στον λογιστή.')
            ->action(function (LeaveRequest $record): void {
                $ok = app(LeaveWorkflow::class)->notifyAccountant($record, (string) $record->accountantOwed());
                $n = Notification::make()->title($ok ? 'Στάλθηκε στον λογιστή' : 'Αποτυχία αποστολής email (δες logs)');
                $ok ? $n->success()->send() : $n->danger()->send();
            });
    }

    /** A decision stands even if the accountant email failed — but say so. */
    public static function warnIfAccountantMissed(?LeaveRequest $leave): void
    {
        if ($leave?->accountantOwed() !== null) {
            Notification::make()
                ->title('Το email στον λογιστή ΔΕΝ στάλθηκε')
                ->body('Η απόφαση καταχωρήθηκε· δοκιμάστε «Email στον λογιστή» ή ενημερώστε τον χειροκίνητα.')
                ->warning()
                ->persistent()
                ->send();
        }
    }

    /** Run a workflow step as the current user; surface a guard failure as a notification. */
    private static function run(callable $step, string $success): void
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            return;
        }

        try {
            $step($user);
            Notification::make()->title($success)->success()->send();
        } catch (RuntimeException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        }
    }
}
