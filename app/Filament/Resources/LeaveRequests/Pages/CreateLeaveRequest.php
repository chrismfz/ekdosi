<?php

namespace App\Filament\Resources\LeaveRequests\Pages;

use App\Enums\LeaveStatus;
use App\Filament\Resources\LeaveRequests\LeaveRequestActions;
use App\Filament\Resources\LeaveRequests\LeaveRequestResource;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Policies\LeaveRequestPolicy;
use App\Services\Hr\LeaveWorkflow;
use App\Support\Hr\WorkingDays;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CreateLeaveRequest extends CreateRecord
{
    protected static string $resource = LeaveRequestResource::class;

    protected static bool $canCreateAnother = false;

    /**
     * Wrap create in a transaction (the panel default is off): the employee-row
     * lock below then really serializes overlapping requests, and «Έγκριση
     * αμέσως» (DB::afterCommit) runs after the row is committed.
     */
    protected ?bool $hasDatabaseTransactions = true;

    /** Longest single leave (calendar days) — catches a 2062-for-2026 typo. */
    public const MAX_SPAN_DAYS = 366;

    public function getTitle(): string
    {
        return 'Νέο αίτημα άδειας';
    }

    /** Staff without an employee record can't file (nothing to attach it to). */
    public function mount(): void
    {
        parent::mount();

        if (! LeaveRequestPolicy::isApprover(auth()->user()) && $this->ownEmployee() === null) {
            Notification::make()
                ->title('Δεν υπάρχει καρτέλα εργαζομένου για τον λογαριασμό σας')
                ->body('Ζητήστε από τον διαχειριστή να σας συνδέσει με μια καρτέλα στο «Προσωπικό → Εργαζόμενοι».')
                ->danger()
                ->persistent()
                ->send();
            $this->redirect(LeaveRequestResource::getUrl('index'));
        }
    }

    /**
     * Server-side truth, whatever the form posted: tenant, requester, pending
     * status; staff are bound to THEIR employee record and the computed day
     * count; approvers may only pick an employee of this tenant. Overlapping
     * pending/approved leave of the same person is refused.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $tenantId = (int) Filament::getTenant()?->getKey();
        $approver = LeaveRequestPolicy::isApprover(auth()->user());

        $employee = $approver
            ? Employee::query()->where('company_id', $tenantId)->find($data['employee_id'] ?? null)
            : $this->ownEmployee();

        if (! $employee instanceof Employee) {
            $this->fail('Επιλέξτε εργαζόμενο.');
        }

        $from = CarbonImmutable::parse($data['starts_on']);
        $to = CarbonImmutable::parse($data['ends_on']);
        if ($to->lt($from)) {
            $this->fail('Η ημερομηνία «Έως» είναι πριν από την «Από».');
        }
        if ($from->diffInDays($to) > self::MAX_SPAN_DAYS) {
            $this->fail('Μία άδεια δεν μπορεί να ξεπερνά τις '.self::MAX_SPAN_DAYS.' ημέρες — χωρίστε τη σε περισσότερες.');
        }

        // Serialize concurrent requests of the same person (inside the create
        // transaction) so two overlapping ones can't both slip past the check.
        Employee::query()->whereKey($employee->id)->lockForUpdate()->first();

        $overlap = LeaveRequest::query()
            ->where('employee_id', $employee->id)
            ->active()
            ->overlapping($from, $to)
            ->exists();
        if ($overlap) {
            $this->fail('Υπάρχει ήδη αίτημα/άδεια του ίδιου εργαζομένου σε αυτό το διάστημα.');
        }

        $computed = WorkingDays::for($tenantId)->count($from, $to);

        return [
            'company_id' => $tenantId,
            'employee_id' => $employee->id,
            'type' => $data['type'],
            'starts_on' => $from->toDateString(),
            'ends_on' => $to->toDateString(),
            'days' => $approver && isset($data['days']) ? (int) $data['days'] : $computed,
            'status' => LeaveStatus::Pending->value,
            'reason' => $data['reason'] ?? null,
            'requested_by_user_id' => auth()->id(),
        ];
    }

    /** Set only when «Έγκριση αμέσως» actually succeeded (drives the toast). */
    protected bool $approvedNow = false;

    /**
     * Runs AFTER Filament's create transaction commits: the approval takes its
     * own lock + emails the accountant, which must not happen inside (or be
     * rolled back with) the outer create transaction.
     */
    protected function afterCreate(): void
    {
        /** @var LeaveRequest $leave */
        $leave = $this->getRecord();
        $user = auth()->user();
        $approveNow = ($this->data['approve_now'] ?? false) && $user instanceof User && LeaveRequestPolicy::isApprover($user);

        DB::afterCommit(function () use ($leave, $user, $approveNow): void {
            $workflow = app(LeaveWorkflow::class);

            if ($approveNow) {
                try {
                    LeaveRequestActions::warnIfAccountantMissed($workflow->approve($leave, $user));
                    $this->approvedNow = true;

                    return;
                } catch (RuntimeException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();
                }
            }

            $workflow->announceCreated($leave);
        });
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return $this->approvedNow ? 'Η άδεια καταχωρήθηκε και εγκρίθηκε' : 'Το αίτημα στάλθηκε για έγκριση';
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }

    private function ownEmployee(): ?Employee
    {
        return Employee::forUser(auth()->user(), (int) Filament::getTenant()?->getKey());
    }

    private function fail(string $message): never
    {
        Notification::make()->title($message)->danger()->send();
        $this->halt();
    }
}
