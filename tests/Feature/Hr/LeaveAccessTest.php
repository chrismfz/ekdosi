<?php

namespace Tests\Feature\Hr;

use App\Enums\LeaveStatus;
use App\Enums\LeaveType;
use App\Filament\Pages\Assistant;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\LeaveCalendar;
use App\Filament\Pages\MySessions;
use App\Filament\Resources\CompanyHolidays\CompanyHolidayResource;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Resources\LeaveRequests\LeaveRequestResource;
use App\Filament\Resources\LeaveRequests\Pages\CreateLeaveRequest;
use App\Filament\Resources\LeaveRequests\Pages\ListLeaveRequests;
use App\Filament\Resources\LeaveRequests\Pages\ViewLeaveRequest;
use App\Mail\LeaveAccountantMail;
use App\Models\LeaveRequest;
use App\Services\TenantRoleProvisioner;
use App\Support\Hr\ErganiStaff;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

class LeaveAccessTest extends HrTestCase
{
    private function leaveOf($employee, string $from, string $to, LeaveType $type = LeaveType::Annual, LeaveStatus $status = LeaveStatus::Pending): LeaveRequest
    {
        $l = LeaveRequest::create([
            'company_id' => $this->company->id, 'employee_id' => $employee->id, 'type' => $type,
            'starts_on' => $from, 'ends_on' => $to, 'days' => 1,
        ]);
        $l->forceFill(['status' => $status])->save();

        return $l;
    }

    public function test_role_matrix(): void
    {
        $roles = app(TenantRoleProvisioner::class);
        $this->assertEqualsCanonicalizing(
            ['ViewAny:LeaveRequest', 'View:LeaveRequest', 'Create:LeaveRequest', 'View:LeaveCalendar'],
            $roles->defaultPermissionsFor(TenantRoleProvisioner::ROLE_ERGANI)->pluck('name')->all(),
        );

        foreach ([TenantRoleProvisioner::ROLE_OPERATOR, TenantRoleProvisioner::ROLE_ERGANI] as $role) {
            $this->actAs($this->makeUser($role));
            $this->assertTrue(LeaveRequestResource::canAccess(), "{$role} → Άδειες");
            $this->assertTrue(LeaveCalendar::canAccess(), "{$role} → Ημερολόγιο");
            $this->assertFalse(EmployeeResource::canAccess(), "{$role} ✗ Εργαζόμενοι");
            $this->assertFalse(CompanyHolidayResource::canAccess(), "{$role} ✗ Αργίες");
        }

        $this->actAs($this->makeUser(TenantRoleProvisioner::ROLE_COMPANY_ADMIN));
        $this->assertTrue(EmployeeResource::canAccess());
        $this->assertTrue(CompanyHolidayResource::canAccess());
    }

    public function test_staff_file_only_for_themselves_with_server_computed_days(): void
    {
        $me = $this->actAs($this->makeUser(TenantRoleProvisioner::ROLE_OPERATOR));
        $mine = $this->employeeFor($me);
        $other = $this->employeeFor(null, 'Άλλος', 'Κώστας');

        Livewire::test(CreateLeaveRequest::class)
            ->fillForm([
                'employee_id' => $other->id,          // ignored for staff
                'type' => LeaveType::Annual->value,
                'starts_on' => '2026-10-26',
                'ends_on' => '2026-10-30',            // Wed 28/10 is a holiday → 4
                'days' => 99,                         // ignored for staff
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $leave = LeaveRequest::query()->sole();
        $this->assertSame($mine->id, $leave->employee_id);
        $this->assertSame(4, $leave->days);
        $this->assertSame(LeaveStatus::Pending, $leave->status);
        $this->assertSame($me->id, $leave->requested_by_user_id);
    }

    public function test_overlapping_request_is_refused(): void
    {
        $me = $this->actAs($this->makeUser(TenantRoleProvisioner::ROLE_ERGANI));
        $mine = $this->employeeFor($me);
        $this->leaveOf($mine, '2026-10-05', '2026-10-09');

        Livewire::test(CreateLeaveRequest::class)
            ->fillForm(['type' => LeaveType::Annual->value, 'starts_on' => '2026-10-09', 'ends_on' => '2026-10-12'])
            ->call('create');

        $this->assertSame(1, LeaveRequest::query()->count());
    }

    public function test_absurd_span_is_refused(): void
    {
        $me = $this->actAs($this->makeUser(TenantRoleProvisioner::ROLE_OPERATOR));
        $this->employeeFor($me);

        Livewire::test(CreateLeaveRequest::class)
            ->fillForm(['type' => LeaveType::Annual->value, 'starts_on' => '2026-10-05', 'ends_on' => '2062-10-09'])
            ->call('create');

        $this->assertSame(0, LeaveRequest::query()->count());
    }

    public function test_staff_see_only_their_own_and_cannot_decide(): void
    {
        $me = $this->actAs($this->makeUser(TenantRoleProvisioner::ROLE_OPERATOR));
        $mineLeave = $this->leaveOf($this->employeeFor($me), '2026-10-05', '2026-10-05');
        $theirs = $this->leaveOf($this->employeeFor(null, 'Άλλος', 'Κώστας'), '2026-10-06', '2026-10-06');

        Livewire::test(ListLeaveRequests::class)
            ->set('activeTab', 'all')
            ->assertCanSeeTableRecords([$mineLeave])
            ->assertCanNotSeeTableRecords([$theirs])
            ->assertTableActionHidden('approve', $mineLeave);

        $this->assertFalse(auth()->user()->can('view', $theirs));
        $this->assertTrue(auth()->user()->can('cancel', $mineLeave));
        $this->assertFalse(auth()->user()->can('update', $mineLeave));
    }

    public function test_admin_approves_from_view_page(): void
    {
        Mail::fake();
        $this->actAs($this->makeUser(TenantRoleProvisioner::ROLE_COMPANY_ADMIN));
        $leave = $this->leaveOf($this->employeeFor(null), '2026-10-05', '2026-10-07');

        Livewire::test(ViewLeaveRequest::class, ['record' => $leave->getRouteKey()])
            ->callAction('approve', data: ['days' => 3, 'note' => 'καλές διακοπές'])
            ->assertHasNoActionErrors();

        $this->assertSame(LeaveStatus::Approved, $leave->fresh()->status);
        $this->assertNotNull($leave->fresh()->accountant_notified_at);
    }

    public function test_calendar_hides_colleagues_leave_kind_and_pending(): void
    {
        $me = $this->actAs($this->makeUser(TenantRoleProvisioner::ROLE_OPERATOR));
        $this->employeeFor($me);
        $colleague = $this->employeeFor(null, 'Συνάδελφος', 'Γιάννης');
        $this->leaveOf($colleague, '2026-10-05', '2026-10-06', LeaveType::Sick, LeaveStatus::Approved);
        $this->leaveOf($colleague, '2026-10-20', '2026-10-20', LeaveType::Annual, LeaveStatus::Pending);

        $page = Livewire::test(LeaveCalendar::class)->set('month', '2026-10');
        $rows = collect($page->viewData('rows'))->keyBy('id');
        $cells = $rows[$colleague->id]['cells'];

        $this->assertSame('Άδεια', $cells['2026-10-05']['label']);
        $this->assertStringNotContainsString('ασθένειας', $cells['2026-10-05']['title']);
        $this->assertArrayNotHasKey('2026-10-20', $cells, 'colleague pending hidden');
        $this->assertNull($rows[$colleague->id]['remaining']);

        $this->actAs($this->makeUser(TenantRoleProvisioner::ROLE_COMPANY_ADMIN));
        $rows = collect(Livewire::test(LeaveCalendar::class)->set('month', '2026-10')->viewData('rows'))->keyBy('id');
        $this->assertSame(LeaveType::Sick->value, $rows[$colleague->id]['cells']['2026-10-05']['label']);
        $this->assertArrayHasKey('2026-10-20', $rows[$colleague->id]['cells']);
    }

    public function test_calendar_grid_is_not_callable_from_the_browser(): void
    {
        $me = $this->actAs($this->makeUser(TenantRoleProvisioner::ROLE_ERGANI));
        $this->employeeFor($me);
        $colleague = $this->employeeFor(null, 'Συνάδελφος', 'Γιάννης');
        $colleague->forceFill(['notes' => 'ΙΔΙΩΤΙΚΟ'])->save();

        foreach (['rows', 'days'] as $method) {
            $this->assertFalse((new \ReflectionMethod(LeaveCalendar::class, $method))->isPublic(), "{$method}() must not be a public Livewire method");
        }

        $rows = Livewire::test(LeaveCalendar::class)->viewData('rows');
        $this->assertSame(['id', 'name', 'entitlement', 'cells', 'remaining'], array_keys($rows[0]));
        $this->assertStringNotContainsString('ΙΔΙΩΤΙΚΟ', json_encode($rows));
    }

    public function test_ergani_staff_are_confined_to_the_leave_screens(): void
    {
        $user = $this->actAs($this->makeUser(TenantRoleProvisioner::ROLE_ERGANI));
        $slug = $this->company->slug;

        $this->assertTrue(ErganiStaff::isRestricted($user, $this->company));
        $this->assertFalse(Dashboard::canAccess());
        $this->assertFalse(Assistant::assistantAvailable());

        $leaves = LeaveRequestResource::getUrl('index', tenant: $this->company);
        $this->get("/admin/{$slug}")->assertRedirect();
        $this->get("/admin/{$slug}/invoices")->assertRedirect($leaves);
        $this->get("/admin/{$slug}/my-data-reconciliation")->assertRedirect($leaves);
        $this->get($leaves)->assertOk();
        $this->get(LeaveCalendar::getUrl(tenant: $this->company))->assertOk();
        $this->get(MySessions::getUrl(tenant: $this->company))->assertOk();
    }

    public function test_approve_now_approves_after_commit_and_emails(): void
    {
        Mail::fake();
        $this->actAs($this->makeUser(TenantRoleProvisioner::ROLE_COMPANY_ADMIN));
        $e = $this->employeeFor(null);

        Livewire::test(CreateLeaveRequest::class)
            ->fillForm(['employee_id' => $e->id, 'type' => LeaveType::Annual->value, 'starts_on' => '2026-10-05', 'ends_on' => '2026-10-06', 'days' => 2, 'approve_now' => true])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertNotified('Η άδεια καταχωρήθηκε και εγκρίθηκε');

        $leave = LeaveRequest::query()->sole();
        $this->assertSame(LeaveStatus::Approved, $leave->status);
        $this->assertNull($leave->accountantOwed());
        Mail::assertSent(LeaveAccountantMail::class);
    }

    public function test_operators_are_not_restricted_and_broadcasts_skip_ergani(): void
    {
        $operator = $this->makeUser(TenantRoleProvisioner::ROLE_OPERATOR);
        $ergani = $this->makeUser(TenantRoleProvisioner::ROLE_ERGANI);
        $admin = $this->makeUser(TenantRoleProvisioner::ROLE_COMPANY_ADMIN);

        $this->assertFalse(ErganiStaff::isRestricted($operator, $this->company));
        $this->assertEqualsCanonicalizing(
            [$operator->id, $admin->id],
            ErganiStaff::staffRecipients($this->company)->pluck('id')->all(),
        );

        $this->actAs($operator);
        $this->get('/admin/'.$this->company->slug.'/invoices')->assertOk();
    }
}
