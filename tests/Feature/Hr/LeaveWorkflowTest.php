<?php

namespace Tests\Feature\Hr;

use App\Enums\LeaveStatus;
use App\Enums\LeaveType;
use App\Mail\LeaveAccountantMail;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Services\Hr\LeaveWorkflow;
use App\Services\TenantMailerFactory;
use App\Services\TenantRoleProvisioner;
use App\Support\Hr\ErganiStaff;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

class LeaveWorkflowTest extends HrTestCase
{
    private function leave(Employee $e, string $from = '2026-10-05', string $to = '2026-10-09', array $extra = []): LeaveRequest
    {
        return LeaveRequest::create(array_merge([
            'company_id' => $this->company->id, 'employee_id' => $e->id, 'type' => LeaveType::Annual,
            'starts_on' => $from, 'ends_on' => $to, 'days' => 5, 'requested_by_user_id' => $e->user_id,
        ], $extra));
    }

    public function test_approve_emails_accountant_and_stamps(): void
    {
        Mail::fake();
        $admin = $this->makeUser(TenantRoleProvisioner::ROLE_COMPANY_ADMIN);
        $leave = $this->leave($this->employeeFor($this->makeUser(TenantRoleProvisioner::ROLE_ERGANI)));

        $done = app(LeaveWorkflow::class)->approve($leave, $admin, 'οκ', 4);

        $this->assertSame(LeaveStatus::Approved, $done->status);
        $this->assertSame(4, $done->days);
        $this->assertSame($admin->id, $done->decided_by_user_id);
        $this->assertNotNull($done->accountant_notified_at);
        Mail::assertSent(LeaveAccountantMail::class, fn (LeaveAccountantMail $m) => $m->hasTo('accountant@example.test') && $m->event === 'approved');
        $this->assertSame(4, $done->employee->annualLeaveTaken(2026));
        $this->assertSame(16, $done->employee->annualLeaveRemaining(2026));
    }

    public function test_cannot_decide_twice_and_overlap_is_refused(): void
    {
        Mail::fake();
        $admin = $this->makeUser(TenantRoleProvisioner::ROLE_COMPANY_ADMIN);
        $e = $this->employeeFor(null);
        $first = $this->leave($e);
        app(LeaveWorkflow::class)->approve($first, $admin);

        try {
            app(LeaveWorkflow::class)->reject($first, $admin, 'x');
            $this->fail('second decision must throw');
        } catch (RuntimeException) {
        }
        $this->assertSame(LeaveStatus::Approved, $first->fresh()->status);

        $overlapping = $this->leave($e, '2026-10-08', '2026-10-12');
        $this->expectException(RuntimeException::class);
        app(LeaveWorkflow::class)->approve($overlapping, $admin);
    }

    public function test_cancel_of_approved_emails_cancellation_but_pending_does_not(): void
    {
        Mail::fake();
        $admin = $this->makeUser(TenantRoleProvisioner::ROLE_COMPANY_ADMIN);
        $e = $this->employeeFor(null);

        $pending = $this->leave($e, '2026-11-02', '2026-11-03');
        app(LeaveWorkflow::class)->cancel($pending, $admin);
        Mail::assertNothingSent();

        $approved = app(LeaveWorkflow::class)->approve($this->leave($e), $admin);
        app(LeaveWorkflow::class)->cancel($approved, $admin);
        $this->assertSame(LeaveStatus::Cancelled, $approved->fresh()->status);
        Mail::assertSent(LeaveAccountantMail::class, fn (LeaveAccountantMail $m) => $m->event === 'cancelled');
        $this->assertSame(0, $e->annualLeaveTaken(2026), 'a cancelled leave frees the balance');
    }

    public function test_no_accountant_email_configured_still_approves(): void
    {
        Mail::fake();
        $this->company->forceFill(['leave_notify_email' => null])->save();
        $admin = $this->makeUser(TenantRoleProvisioner::ROLE_COMPANY_ADMIN);

        $done = app(LeaveWorkflow::class)->approve($this->leave($this->employeeFor(null)), $admin);

        $this->assertSame(LeaveStatus::Approved, $done->status);
        $this->assertNull($done->accountant_notified_at);
        Mail::assertNothingSent();
    }

    public function test_approvers_are_admins_not_operators_or_staff(): void
    {
        $admin = $this->makeUser(TenantRoleProvisioner::ROLE_COMPANY_ADMIN);
        $this->makeUser(TenantRoleProvisioner::ROLE_OPERATOR);
        $this->makeUser(TenantRoleProvisioner::ROLE_ERGANI);

        $ids = app(LeaveWorkflow::class)->approvers($this->company)->pluck('id')->all();

        $this->assertSame([$admin->id], $ids);
    }

    public function test_decision_bell_reaches_the_employee_not_the_admin_who_filed(): void
    {
        Mail::fake();
        $admin = $this->makeUser(TenantRoleProvisioner::ROLE_COMPANY_ADMIN);
        $staff = $this->makeUser(TenantRoleProvisioner::ROLE_ERGANI);
        $leave = $this->leave($this->employeeFor($staff), extra: ['requested_by_user_id' => $admin->id]);

        app(LeaveWorkflow::class)->approve($leave, $admin);

        $this->assertSame(1, $staff->notifications()->count());
        $this->assertSame(0, $admin->notifications()->count());
    }

    public function test_cancel_keeps_the_original_approval_audit(): void
    {
        Mail::fake();
        $admin = $this->makeUser(TenantRoleProvisioner::ROLE_COMPANY_ADMIN);
        $other = $this->makeUser(TenantRoleProvisioner::ROLE_COMPANY_ADMIN);
        $approved = app(LeaveWorkflow::class)->approve($this->leave($this->employeeFor(null)), $admin);
        $decidedAt = $approved->decided_at;

        $cancelled = app(LeaveWorkflow::class)->cancel($approved, $other);

        $this->assertSame(LeaveStatus::Cancelled, $cancelled->status);
        $this->assertSame($admin->id, $cancelled->decided_by_user_id);
        $this->assertEquals($decidedAt, $cancelled->decided_at);
    }

    public function test_role_change_is_seen_after_the_scoped_memo_is_forgotten(): void
    {
        $user = $this->makeUser(TenantRoleProvisioner::ROLE_OPERATOR);
        $this->assertFalse(ErganiStaff::isRestricted($user, $this->company));

        app(TenantRoleProvisioner::class)->setRoleInCompany($user, $this->company, TenantRoleProvisioner::ROLE_ERGANI);
        app()->forgetScopedInstances();   // what the queue worker does between jobs

        $this->assertTrue(ErganiStaff::isRestricted($user, $this->company));
    }

    public function test_failed_cancellation_email_stays_owed_and_is_resendable(): void
    {
        Mail::fake();
        $admin = $this->makeUser(TenantRoleProvisioner::ROLE_COMPANY_ADMIN);
        $approved = app(LeaveWorkflow::class)->approve($this->leave($this->employeeFor(null)), $admin);
        $this->assertNull($approved->accountantOwed(), 'approval delivered');

        // SMTP breaks before the revocation.
        $this->mock(TenantMailerFactory::class, fn ($m) => $m->shouldReceive('for')->andThrow(new RuntimeException('smtp down')));
        $cancelled = app(LeaveWorkflow::class)->cancel($approved, $admin);

        $this->assertSame('cancelled', $cancelled->fresh()->accountantOwed(), 'owed, not hidden behind the old timestamp');

        $this->app->forgetInstance(TenantMailerFactory::class);
        $this->app->forgetInstance(LeaveWorkflow::class);
        $this->assertTrue(app(LeaveWorkflow::class)->notifyAccountant($cancelled->fresh(), 'cancelled'));
        $this->assertNull($cancelled->fresh()->accountantOwed());
    }

    public function test_cancelling_an_approval_the_accountant_never_got_sends_nothing(): void
    {
        Mail::fake();
        $this->company->forceFill(['leave_notify_email' => null])->save();
        $admin = $this->makeUser(TenantRoleProvisioner::ROLE_COMPANY_ADMIN);
        $approved = app(LeaveWorkflow::class)->approve($this->leave($this->employeeFor(null)), $admin);
        $this->assertSame('approved', $approved->accountant_owed_event);

        $this->company->forceFill(['leave_notify_email' => 'accountant@example.test'])->save();
        $cancelled = app(LeaveWorkflow::class)->cancel($approved->fresh(), $admin);

        $this->assertNull($cancelled->accountant_owed_event);
        Mail::assertNothingSent();
    }

    public function test_cancel_racing_the_approval_email_leaves_the_revocation_owed(): void
    {
        $admin = $this->makeUser(TenantRoleProvisioner::ROLE_COMPANY_ADMIN);
        $leave = $this->leave($this->employeeFor(null));

        // The approval email is «in flight» when another approver cancels.
        $mailer = \Mockery::mock(Mailer::class);
        $pending = \Mockery::mock();
        $mailer->shouldReceive('to')->andReturn($pending);
        $pending->shouldReceive('send')->andReturnUsing(function () use ($leave, $admin): void {
            static $once = false;
            if (! $once) {
                $once = true;
                app(LeaveWorkflow::class)->cancel($leave->fresh(), $admin);
            }
        });
        $this->mock(TenantMailerFactory::class, fn ($m) => $m->shouldReceive('for')->andReturn($mailer));

        app(LeaveWorkflow::class)->approve($leave, $admin);

        $row = $leave->fresh();
        $this->assertSame(LeaveStatus::Cancelled, $row->status);
        $this->assertSame('cancelled', $row->accountant_owed_event, 'the stale approval must be flagged for revocation');
    }
}
