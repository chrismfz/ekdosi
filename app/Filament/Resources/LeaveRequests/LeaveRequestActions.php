<?php

namespace App\Filament\Resources\LeaveRequests;

use App\Enums\LeaveStatus;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\Ergani\ErganiClient;
use App\Services\Ergani\LeaveErganiSubmitter;
use App\Services\Hr\LeaveWorkflow;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
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
                    : ' — ΔΕΝ έχει οριστεί email λογιστή (Ρυθμίσεις εταιρείας → «Προσωπικό — ΕΡΓΑΝΗ»): ενημερώστε τον χειροκίνητα.'))
            ->fillForm(fn (LeaveRequest $record): array => ['days' => $record->days])
            ->schema([
                TextInput::make('days')->label('Εργάσιμες ημέρες')->numeric()->integer()->minValue(0)->maxValue(366)->required(),
                Textarea::make('note')->label('Σημείωση (προαιρετικά)')->rows(2)->maxLength(1000),
            ])
            ->action(function (LeaveRequest $record, array $data): void {
                self::run(fn (User $u) => app(LeaveWorkflow::class)->approve($record, $u, $data['note'] ?? null, (int) $data['days']), 'Η άδεια εγκρίθηκε', $record);
                self::warnAboutFailedSideEffects($record->fresh());
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
                self::run(fn (User $u) => app(LeaveWorkflow::class)->reject($record, $u, $data['note']), 'Η άδεια απορρίφθηκε', $record);
            });
    }

    public static function cancel(): Action
    {
        return Action::make('cancelLeave')
            ->label(fn (LeaveRequest $record): string => $record->isApproved() ? 'Ανάκληση' : 'Ακύρωση αιτήματος')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('gray')
            // State check HERE, not only in the policy: a super_admin bypasses every
            // policy (Gate::before), so the policy alone would show «Ακύρωση» on a
            // leave that is already rejected/cancelled.
            ->visible(fn (LeaveRequest $record): bool => ($record->isPending() || $record->isApproved())
                && (auth()->user()?->can('cancel', $record) ?? false))
            ->authorize(fn (LeaveRequest $record): bool => auth()->user()?->can('cancel', $record) ?? false)
            ->requiresConfirmation()
            ->modalDescription(fn (LeaveRequest $record): string => match (true) {
                ! $record->isApproved() => 'Το αίτημα θα αποσυρθεί.',
                filled($record->company?->leave_notify_email) && $record->accountant_owed_event === null && $record->accountant_notified_at !== null => 'Η άδεια είχε εγκριθεί και ο λογιστής ενημερώθηκε — θα σταλεί email ακύρωσης.',
                default => 'Η άδεια είχε εγκριθεί αλλά ο λογιστής ΔΕΝ είχε ενημερωθεί με email — αν τον είχατε ενημερώσει χειροκίνητα, ενημερώστε τον και για την ακύρωση.',
            })
            ->action(function (LeaveRequest $record): void {
                self::run(fn (User $u) => app(LeaveWorkflow::class)->cancel($record, $u), 'Ακυρώθηκε', $record);
                self::warnAboutFailedSideEffects($record->fresh());
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
                $record->refresh();
                $n = Notification::make()->title($ok ? 'Στάλθηκε στον λογιστή' : 'Αποτυχία αποστολής email (δες logs)');
                $ok ? $n->success()->send() : $n->danger()->send();
            });
    }

    /** Retry the ΕΡΓΑΝΗ declaration of an approved leave (first try failed / opted in later). */
    public static function submitToErgani(): Action
    {
        return Action::make('submitToErgani')
            ->label('Δήλωση στο ΕΡΓΑΝΗ')
            ->icon('heroicon-o-cloud-arrow-up')
            ->color('warning')
            ->visible(fn (LeaveRequest $record): bool => $record->isApproved()
                && (in_array($record->ergani_status, [null, 'failed', 'unknown'], true) || self::staleClaim($record))
                && LeaveErganiSubmitter::enabledFor($record->company)
                && (auth()->user()?->can('update', $record) ?? false))
            ->authorize(fn (LeaveRequest $record): bool => auth()->user()?->can('update', $record) ?? false)
            ->requiresConfirmation()
            ->modalDescription(fn (LeaveRequest $record): string => (match (true) {
                self::accountantWasAsked($record) => '⚠ Ο ΛΟΓΙΣΤΗΣ έχει ήδη ενημερωθεί με email να δηλώσει ο ίδιος αυτή την άδεια. Συνεχίστε ΜΟΝΟ αν ελέγξατε στο ΕΡΓΑΝΗ ότι ΔΕΝ τη δήλωσε — αλλιώς θα δηλωθεί δύο φορές (θα του σταλεί ενημέρωση). ',
                self::needsConfirmation($record) => '⚠ Η προηγούμενη δήλωση έχει ΑΓΝΩΣΤΟ αποτέλεσμα (το ΕΡΓΑΝΗ δεν απάντησε). Συνεχίστε ΜΟΝΟ αν ελέγξατε στο ΕΡΓΑΝΗ ότι ΔΕΝ καταχωρήθηκε — αλλιώς θα δηλωθεί δύο φορές. ',
                default => '',
            })
                .($record->company?->ergani_mode === 'production'
                    ? '⚠ ΠΑΡΑΓΩΓΗ — η άδεια θα δηλωθεί ΠΡΑΓΜΑΤΙΚΑ στο ΕΡΓΑΝΗ.'
                    : 'Δοκιμαστικό περιβάλλον — η δήλωση θα φέρει «ΑΚΥΡΟ».'))
            // The operator's confirmation covers ONLY the state the modal showed:
            // if another attempt turned it «unknown» after the modal opened, the
            // claim refuses instead of silently confirming what they never saw.
            // …and to the ENVIRONMENT it showed (a trial↔production switch in the
            // meantime must not turn a «Δοκιμαστικό» confirmation into a real filing).
            ->fillForm(fn (LeaveRequest $record): array => [
                'seen' => self::confirmationKind($record) ?? 'plain',
                'seen_mode' => $record->company?->ergani_mode,
            ])
            ->schema([Hidden::make('seen'), Hidden::make('seen_mode')])
            ->action(function (LeaveRequest $record, array $data): void {
                if (($data['seen_mode'] ?? null) !== $record->company?->ergani_mode) {
                    Notification::make()->title('Το περιβάλλον ΕΡΓΑΝΗ άλλαξε στο μεταξύ — δεν στάλθηκε τίποτα. Ξαναδοκιμάστε.')->warning()->send();

                    return;
                }
                $accountantWasAsked = self::accountantWasAsked($record);
                $seen = $data['seen'] ?? null;
                $ok = app(LeaveErganiSubmitter::class)->submit($record, (int) auth()->id(),
                    confirmed: in_array($seen, ['accountant', 'unknown'], true) ? $seen : null);
                $record->refresh();
                // Only if it is STILL declared and approved (a racing revocation may
                // already have withdrawn it — then «declared» would mislead him).
                if ($ok && $accountantWasAsked && $record->isApproved() && $record->ergani_status === 'submitted') {
                    // He was asked to declare it himself — tell him ekdosi just did.
                    app(LeaveWorkflow::class)->notifyAccountant($record, 'declared');
                }
                $n = Notification::make()->title($ok ? 'Δηλώθηκε στο ΕΡΓΑΝΗ — πρωτ. '.$record->ergani_protocol : 'Αποτυχία δήλωσης ΕΡΓΑΝΗ')
                    ->body($ok ? null : $record->ergani_error);
                $ok ? $n->success()->send() : $n->danger()->persistent()->send();
            });
    }

    /** Download ΕΡΓΑΝΗ's own PDF of the declaration (the legal proof). */
    public static function erganiPdf(): Action
    {
        return Action::make('erganiPdf')
            ->label('PDF ΕΡΓΑΝΗ')
            ->icon('heroicon-o-document-arrow-down')
            ->color('gray')
            ->visible(fn (LeaveRequest $record): bool => $record->ergani_status === 'submitted'
                && filled($record->ergani_protocol)
                && (auth()->user()?->can('update', $record) ?? false))
            ->authorize(fn (LeaveRequest $record): bool => auth()->user()?->can('update', $record) ?? false)
            ->action(function (LeaveRequest $record) {
                $company = $record->company->replicate()->forceFill(['ergani_mode' => $record->ergani_env ?: $record->company->ergani_mode]);
                try {
                    $pdf = (new ErganiClient($company))->pdf(LeaveErganiSubmitter::DOCUMENT, (string) $record->ergani_protocol, (string) $record->erganiSubmitDateYmd());
                } catch (\Throwable $e) {
                    $pdf = null;
                }
                if ($pdf === null) {
                    Notification::make()->title('Το ΕΡΓΑΝΗ δεν επέστρεψε PDF')->danger()->send();

                    return null;
                }

                return response()->streamDownload(fn () => print ($pdf), 'ergani-'.preg_replace('/[^\w]+/u', '-', (string) $record->ergani_protocol).'.pdf', ['Content-Type' => 'application/pdf']);
            });
    }

    /**
     * After an «unknown» outcome the operator checks ΕΡΓΑΝΗ; if the leave WAS
     * registered, they record its protocol here (then it can be withdrawn —
     * automatically, if the leave was revoked meanwhile).
     */
    public static function recordErganiProtocol(): Action
    {
        return Action::make('recordErganiProtocol')
            ->label('Καταχώριση πρωτοκόλλου ΕΡΓΑΝΗ')
            ->icon('heroicon-o-pencil-square')
            ->color('gray')
            ->visible(fn (LeaveRequest $record): bool => ($record->ergani_status === 'unknown' || self::staleClaim($record))
                && (auth()->user()?->can('update', $record) ?? false))
            ->authorize(fn (LeaveRequest $record): bool => auth()->user()?->can('update', $record) ?? false)
            ->modalDescription('Αν βρήκατε τη δήλωση στο ΕΡΓΑΝΗ, καταχωρίστε το πρωτόκολλό της όπως εμφανίζεται (π.χ. «ΑΚ - ΟΡ359880»).')
            ->schema([
                TextInput::make('protocol')->label('Αριθμός πρωτοκόλλου')->required()->maxLength(50),
                DatePicker::make('submitted_on')->label('Ημερομηνία υποβολής')->required()->native(false)->displayFormat('d/m/Y')->default(now()),
            ])
            ->action(function (LeaveRequest $record, array $data): void {
                $ok = app(LeaveErganiSubmitter::class)->recordProtocol($record, (string) $data['protocol'],
                    CarbonImmutable::parse($data['submitted_on'], 'Europe/Athens')->setTime(12, 0), (int) auth()->id());
                if (! $ok) {
                    Notification::make()->title('Η κατάσταση άλλαξε στο μεταξύ — ανανεώστε τη σελίδα.')->warning()->send();

                    return;
                }
                $record->refresh();
                Notification::make()->title('Καταχωρίστηκε το πρωτόκολλο '.$data['protocol'])->success()->send();
                self::warnAboutFailedSideEffects($record->fresh());
            });
    }

    /**
     * The operator must confirm «it's NOT in ΕΡΓΑΝΗ» before declaring: an unknown /
     * crashed attempt, or a leave whose approval email asked the accountant to
     * declare it by hand (failed auto-declaration, or opted in later).
     */
    public static function needsConfirmation(LeaveRequest $record): bool
    {
        return self::confirmationKind($record) !== null;
    }

    /** Which «it's NOT in ΕΡΓΑΝΗ» the operator is asked to confirm (see LeaveErganiSubmitter::submit). */
    public static function confirmationKind(LeaveRequest $record): ?string
    {
        return match (true) {
            $record->ergani_status === 'unknown' || self::staleClaim($record) => 'unknown',
            self::accountantWasAsked($record) => 'accountant',
            default => null,
        };
    }

    public static function accountantWasAsked(LeaveRequest $record): bool
    {
        return in_array($record->ergani_status, [null, 'failed'], true) && $record->accountant_notified_at !== null;
    }

    private static function staleClaim(LeaveRequest $record): bool
    {
        return $record->ergani_status === 'submitting'
            && $record->updated_at?->lt(now()->subMinutes(LeaveErganiSubmitter::CLAIM_STALE_MINUTES));
    }

    /** Retry withdrawing the declaration of a revoked leave. */
    public static function cancelInErgani(): Action
    {
        return Action::make('cancelInErgani')
            ->label('Ανάκληση στο ΕΡΓΑΝΗ')
            ->icon('heroicon-o-cloud-arrow-down')
            ->color('warning')
            ->visible(fn (LeaveRequest $record): bool => ($record->ergani_status === 'cancel_failed'
                    // a revoked leave still declared (a withdrawal that never ran)
                    || ($record->ergani_status === 'submitted' && $record->status === LeaveStatus::Cancelled)
                    || ($record->ergani_status === 'cancelling' && $record->updated_at?->lt(now()->subMinutes(LeaveErganiSubmitter::CLAIM_STALE_MINUTES))))
                && (auth()->user()?->can('update', $record) ?? false))
            ->authorize(fn (LeaveRequest $record): bool => auth()->user()?->can('update', $record) ?? false)
            ->requiresConfirmation()
            ->action(function (LeaveRequest $record): void {
                $ok = app(LeaveErganiSubmitter::class)->cancel($record, (int) auth()->id());
                $record->refresh();
                $n = Notification::make()->title($ok ? 'Ανακλήθηκε στο ΕΡΓΑΝΗ' : 'Αποτυχία ανάκλησης ΕΡΓΑΝΗ')->body($ok ? null : $record->ergani_error);
                $ok ? $n->success()->send() : $n->danger()->persistent()->send();
            });
    }

    /** A decision stands even if the accountant email / ΕΡΓΑΝΗ call failed — but say so. */
    public static function warnAboutFailedSideEffects(?LeaveRequest $leave): void
    {
        if ($leave !== null && in_array($leave->ergani_status, ['failed', 'cancel_failed', 'unknown'], true)) {
            Notification::make()
                ->title(match ($leave->ergani_status) {
                    'failed' => 'Η δήλωση στο ΕΡΓΑΝΗ ΑΠΕΤΥΧΕ',
                    'unknown' => 'Η δήλωση στο ΕΡΓΑΝΗ έχει ΑΓΝΩΣΤΟ αποτέλεσμα',
                    default => 'Η ανάκληση στο ΕΡΓΑΝΗ ΑΠΕΤΥΧΕ',
                })
                ->body((string) $leave->ergani_error)
                ->danger()
                ->persistent()
                ->send();
        }

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
    private static function run(callable $step, string $success, ?LeaveRequest $record = null): void
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
        } finally {
            // The workflow worked on its own (locked, fresh) copy — refresh the one
            // the page renders, or the view keeps showing the old state/actions.
            $record?->refresh();
        }
    }
}
