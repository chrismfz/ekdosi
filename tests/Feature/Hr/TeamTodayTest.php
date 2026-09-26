<?php

namespace Tests\Feature\Hr;

use App\Enums\LeaveStatus;
use App\Enums\LeaveType;
use App\Filament\Pages\Dashboard;
use App\Filament\Resources\LeaveRequests\Pages\CreateLeaveRequest;
use App\Filament\Widgets\TeamTodayWidget;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\WorkCardEvent;
use App\Services\TenantRoleProvisioner;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

/** «Η ομάδα σήμερα» dashboard card + «Το υπόλοιπό μου» on the leave form. */
class TeamTodayTest extends HrTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach (['View:WorkCard', 'ViewAny:WorkCardEvent'] as $p) {
            Permission::findOrCreate($p, 'web');
        }
        app(TenantRoleProvisioner::class)->ensureStandardRoles($this->company);
        $this->travelTo('2026-10-06 10:00:00');   // Tuesday
    }

    private function leave(Employee $e, string $from, string $to, LeaveType $type = LeaveType::Annual, LeaveStatus $status = LeaveStatus::Approved, int $days = 1): LeaveRequest
    {
        return LeaveRequest::create(['company_id' => $this->company->id, 'employee_id' => $e->id, 'type' => $type,
            'starts_on' => $from, 'ends_on' => $to, 'days' => $days, 'status' => $status]);
    }

    public function test_admin_sees_who_is_away_this_week_pending_and_presence(): void
    {
        $a = $this->employeeFor(null, 'Αλφα', 'Νίκος');
        $b = $this->employeeFor(null, 'Βήτα', 'Ηλίας');
        $this->leave($a, '2026-10-05', '2026-10-07', LeaveType::Sick);
        $this->leave($b, '2026-10-08', '2026-10-08');
        $this->leave($b, '2026-10-20', '2026-10-20', status: LeaveStatus::Pending);
        WorkCardEvent::create(['company_id' => $this->company->id, 'employee_id' => $b->id, 'type' => WorkCardEvent::IN,
            'occurred_at' => now()->subHour(), 'reference_date' => '2026-10-06', 'source' => 'self']);

        $this->actAs($this->makeUser(TenantRoleProvisioner::ROLE_COMPANY_ADMIN));
        Livewire::test(TeamTodayWidget::class)
            ->assertSee('Αλφα Νίκος · Άδεια ασθένειας', false)
            ->assertSee('Βήτα Ηλίας')
            ->assertSee('Πε 8/10', false)
            ->assertSee('Περιμένουν έγκριση')
            ->assertSee('Μέσα τώρα (κάρτα)')
            ->assertSee('Βήτα Ηλίας (09:00)');
    }

    public function test_colleagues_see_who_but_not_the_type_nor_pending(): void
    {
        $a = $this->employeeFor(null, 'Αλφα', 'Νίκος');
        $this->leave($a, '2026-10-05', '2026-10-07', LeaveType::Sick);

        $this->actAs($this->makeUser(TenantRoleProvisioner::ROLE_OPERATOR));
        $this->assertTrue(TeamTodayWidget::canView());
        Livewire::test(TeamTodayWidget::class)
            ->assertSee('Αλφα Νίκος')
            ->assertDontSee('ασθένειας')
            ->assertDontSee('Περιμένουν έγκριση')
            ->assertDontSee('Μέσα τώρα');

        $this->actAs($this->makeUser(TenantRoleProvisioner::ROLE_ERGANI));
        $this->assertFalse(Dashboard::canAccess(), 'ergani staff never reach the dashboard');
    }

    public function test_leave_form_shows_the_balance_after_this_request_and_flags_an_overdraft(): void
    {
        $user = $this->actAs($this->makeUser(TenantRoleProvisioner::ROLE_ERGANI));
        $me = $this->employeeFor($user);
        $me->forceFill(['annual_leave_days' => 20])->save();
        $this->leave($me, '2026-09-01', '2026-09-14', days: 10);

        Livewire::test(CreateLeaveRequest::class)
            ->fillForm(['type' => LeaveType::Annual->value, 'starts_on' => '2026-10-12', 'ends_on' => '2026-10-14'])
            ->assertSee('Μένουν <strong>10</strong> από 20 (2026)', false)
            ->assertSee('με αυτή την αίτηση <strong>7</strong>', false)
            ->fillForm(['starts_on' => '2026-10-12', 'ends_on' => '2026-10-30'])
            ->assertSee('ξεπερνά το υπόλοιπο κατά 4', false);   // 14 εργάσιμες (28/10 αργία) − 10
    }

    public function test_a_request_crossing_new_year_shows_both_years(): void
    {
        $user = $this->actAs($this->makeUser(TenantRoleProvisioner::ROLE_ERGANI));
        $this->employeeFor($user)->forceFill(['annual_leave_days' => 20])->save();

        Livewire::test(CreateLeaveRequest::class)
            ->fillForm(['type' => LeaveType::Annual->value, 'starts_on' => '2026-12-29', 'ends_on' => '2027-01-05'])
            ->assertSee('(2026) → με αυτή την αίτηση <strong>17</strong>', false)
            ->assertSee('(2027) → με αυτή την αίτηση <strong>18</strong>', false);
    }
}
