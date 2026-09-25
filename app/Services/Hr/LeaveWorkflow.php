<?php

namespace App\Services\Hr;

use App\Enums\LeaveStatus;
use App\Filament\Resources\LeaveRequests\LeaveRequestResource;
use App\Mail\LeaveAccountantMail;
use App\Models\Company;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\Ergani\LeaveErganiSubmitter;
use App\Services\TenantMailerFactory;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;

/**
 * The ONE place a leave request changes state (approve / reject / cancel) —
 * so the side effects can't be skipped by another code path:
 *
 *   - approve  → declare to ΕΡΓΑΝΗ (WTOLeave, if the tenant opted in) + email the
 *                accountant (companies.leave_notify_email) + bell the employee
 *   - reject   → bell the requester
 *   - cancel   → of an APPROVED leave the accountant was told about: email the
 *                cancellation; bell the employee when someone else cancels
 *   - created  → bell the approvers (users who hold Update:LeaveRequest)
 *
 * The status flip runs under lockForUpdate so two approvers can't double-decide;
 * the email/bell run AFTER commit and never roll the decision back. What the
 * accountant still has to be told lives in `accountant_owed_event` — a failed
 * mail leaves it set (visible + «Email στον λογιστή» re-sends it).
 */
class LeaveWorkflow
{
    public function __construct(
        private readonly TenantMailerFactory $mailers,
        private readonly LeaveErganiSubmitter $ergani,
    ) {}

    public function approve(LeaveRequest $leave, User $by, ?string $note = null, ?int $days = null): LeaveRequest
    {
        $leave = $this->transition($leave, [LeaveStatus::Pending], function (LeaveRequest $row) use ($by, $note, $days): void {
            // Serialize approvals PER EMPLOYEE: two approvers deciding two
            // overlapping requests of the same person at once would otherwise
            // each see «no approved overlap» and both commit.
            Employee::query()->withoutGlobalScopes()->whereKey($row->employee_id)->lockForUpdate()->first();

            $overlap = LeaveRequest::query()
                ->where('employee_id', $row->employee_id)
                ->whereKeyNot($row->getKey())
                ->where('status', LeaveStatus::Approved->value)
                ->overlapping($row->starts_on, $row->ends_on)
                ->exists();
            if ($overlap) {
                throw new RuntimeException('Υπάρχει ήδη εγκεκριμένη άδεια του ίδιου εργαζομένου που επικαλύπτει αυτό το διάστημα.');
            }

            $row->forceFill([
                'status' => LeaveStatus::Approved,
                'decided_by_user_id' => $by->getKey(),
                'decided_at' => now(),
                'decision_note' => $note,
                'days' => $days ?? $row->days,
                'accountant_owed_event' => 'approved',
            ])->save();
        });

        // ΕΡΓΑΝΗ first (when the tenant opted in), so the accountant email can
        // carry the protocol number instead of asking for a declaration.
        $this->ergani->submit($leave, (int) $by->getKey());
        $this->notifyAccountant($leave, 'approved');
        $this->bellRequester($leave, 'Η άδειά σας εγκρίθηκε', 'success');

        return $leave;
    }

    public function reject(LeaveRequest $leave, User $by, ?string $note = null): LeaveRequest
    {
        $leave = $this->transition($leave, [LeaveStatus::Pending], function (LeaveRequest $row) use ($by, $note): void {
            $row->forceFill([
                'status' => LeaveStatus::Rejected,
                'decided_by_user_id' => $by->getKey(),
                'decided_at' => now(),
                'decision_note' => $note,
            ])->save();
        });

        $this->bellRequester($leave, 'Η άδειά σας απορρίφθηκε', 'danger');

        return $leave;
    }

    /**
     * Withdraw a pending request (its owner or an approver) or revoke an approved
     * one (approvers only — enforced by the caller's authorization).
     */
    public function cancel(LeaveRequest $leave, User $by, ?string $note = null): LeaveRequest
    {
        $wasApproved = false;

        $leave = $this->transition($leave, [LeaveStatus::Pending, LeaveStatus::Approved], function (LeaveRequest $row) use ($note, &$wasApproved): void {
            $wasApproved = $row->status === LeaveStatus::Approved;
            // The accountant must hear of the revocation ONLY if they were told of
            // the approval; an approval still owed (never sent) simply isn't owed any more.
            $owed = $wasApproved && $row->accountant_owed_event === null && $row->accountant_notified_at !== null
                ? 'cancelled'
                : null;
            // decided_by/decided_at keep the ORIGINAL decision (who approved);
            // who cancelled is in the activity log (causer).
            $row->forceFill([
                'status' => LeaveStatus::Cancelled,
                'decision_note' => $note ?? $row->decision_note,
                'accountant_owed_event' => $owed,
            ])->save();
        });

        // Withdraw the ΕΡΓΑΝΗ declaration if one was made (CancelSubmittedDocument).
        $this->ergani->cancel($leave, (int) $by->getKey());
        if ($leave->accountant_owed_event === 'cancelled') {
            $this->notifyAccountant($leave, 'cancelled');
        }
        if ((int) $this->personOf($leave)?->getKey() !== (int) $by->getKey()) {
            $this->bellRequester($leave, $wasApproved ? 'Η εγκεκριμένη άδειά σας ανακλήθηκε' : 'Το αίτημα άδειας ακυρώθηκε', 'warning');
        }

        return $leave;
    }

    /** Bell every approver of the tenant about a new pending request. */
    public function announceCreated(LeaveRequest $leave): void
    {
        try {
            $company = $leave->company;
            if (! $company instanceof Company) {
                return;
            }

            $recipients = $this->approvers($company)
                ->reject(fn (User $u): bool => (int) $u->getKey() === (int) $leave->requested_by_user_id);
            if ($recipients->isEmpty()) {
                return;
            }

            Notification::make()
                ->title('Νέο αίτημα άδειας')
                ->body(sprintf('%s — %s (%s, %d εργάσιμες)',
                    $leave->employee?->full_name, $leave->periodLabel(), $leave->type?->getLabel(), $leave->days))
                ->icon('heroicon-o-calendar-days')
                ->warning()
                ->actions([
                    Action::make('view')->label('Προβολή')
                        ->url(LeaveRequestResource::getUrl('view', ['record' => $leave, 'tenant' => $company]))
                        ->markAsRead(),
                ])
                ->sendToDatabase($recipients);
        } catch (\Throwable $e) {
            Log::warning('LeaveWorkflow: approver bell failed', ['leave' => $leave->getKey(), 'error' => $e->getMessage()]);
        }
    }

    /**
     * Email the accountant. Returns false (and logs) when no address is set or
     * the send fails — the decision itself stands either way.
     *
     * @param  'approved'|'cancelled'|'declared'  $event  declared = ekdosi declared it
     *                                                    LATE, after the accountant was asked to
     */
    public function notifyAccountant(LeaveRequest $leave, string $event): bool
    {
        $company = $leave->company;
        $to = trim((string) $company?->leave_notify_email);
        if (! $company instanceof Company || $to === '' || ! in_array($event, ['approved', 'cancelled', 'declared'], true)) {
            return false;
        }

        try {
            $leave->loadMissing('employee');
            $this->mailers->for($company)->to($to)->send(new LeaveAccountantMail(
                $leave,
                $event,
                $company->mail_from_address ?: (string) config('mail.from.address'),
                $company->mail_from_name ?: $company->name,
            ));
            // Re-read under lock: a cancel that raced this send (it saw the approval
            // as not-yet-delivered, so owed nothing) must now owe the revocation —
            // else the accountant keeps a stale «approved» for a cancelled leave.
            DB::transaction(function () use ($leave, $event): void {
                $row = LeaveRequest::query()->withoutGlobalScopes()->whereKey($leave->getKey())->lockForUpdate()->first();
                if ($row === null) {
                    return;
                }
                $raced = $event === 'approved' && $row->status === LeaveStatus::Cancelled;
                // «declared» is an FYI on top — it must never clear an owed
                // approval/revocation email.
                $row->forceFill($event === 'declared'
                    ? ['accountant_notified_at' => now()]
                    : ['accountant_notified_at' => now(), 'accountant_owed_event' => $raced ? 'cancelled' : null],
                )->saveQuietly();
                $leave->setRawAttributes($row->getAttributes(), true);
            });

            return true;
        } catch (\Throwable $e) {
            Log::warning('LeaveWorkflow: accountant email failed', ['leave' => $leave->getKey(), 'error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Users of the tenant who may decide leave requests (Update:LeaveRequest),
     * evaluated with the tenant's permission team set (teams mode).
     *
     * @return Collection<int, User>
     */
    public function approvers(Company $company): Collection
    {
        $registrar = app(PermissionRegistrar::class);
        $previous = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId($company->getKey());

        try {
            return $company->users()->get()
                ->each(fn (User $u) => $u->unsetRelation('roles')->unsetRelation('permissions'))
                ->filter(fn (User $u): bool => Gate::forUser($u)->allows('Update:LeaveRequest'))
                ->values();
        } finally {
            $registrar->setPermissionsTeamId($previous);
        }
    }

    /**
     * Lock the row, assert it is in one of $from, run $mutate, all in one
     * transaction. Returns the fresh model.
     *
     * @param  list<LeaveStatus>  $from
     */
    private function transition(LeaveRequest $leave, array $from, callable $mutate): LeaveRequest
    {
        return DB::transaction(function () use ($leave, $from, $mutate): LeaveRequest {
            /** @var LeaveRequest $row */
            $row = LeaveRequest::query()
                ->withoutGlobalScopes()
                ->whereKey($leave->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! in_array($row->status, $from, true)) {
                throw new RuntimeException('Το αίτημα έχει ήδη κριθεί ('.$row->status?->getLabel().').');
            }

            $mutate($row);

            return $row->fresh(['employee', 'company']);
        });
    }

    /**
     * Who the leave is ABOUT: the employee's own login, else whoever filed it
     * (an admin filing for a login-less employee).
     */
    private function personOf(LeaveRequest $leave): ?User
    {
        return $leave->employee?->user ?? $leave->requestedBy;
    }

    private function bellRequester(LeaveRequest $leave, string $title, string $status): void
    {
        try {
            $user = $this->personOf($leave);
            if (! $user instanceof User || ! $leave->company instanceof Company) {
                return;
            }

            Notification::make()
                ->title($title)
                ->body(trim($leave->periodLabel().' · '.$leave->type?->getLabel()
                    .($leave->decision_note ? ' — '.$leave->decision_note : '')))
                ->icon('heroicon-o-calendar-days')
                ->status($status)
                ->actions([
                    Action::make('view')->label('Προβολή')
                        ->url(LeaveRequestResource::getUrl('view', ['record' => $leave, 'tenant' => $leave->company]))
                        ->markAsRead(),
                ])
                ->sendToDatabase($user);
        } catch (\Throwable $e) {
            Log::warning('LeaveWorkflow: requester bell failed', ['leave' => $leave->getKey(), 'error' => $e->getMessage()]);
        }
    }
}
