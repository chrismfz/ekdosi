<?php

namespace Tests\Feature\Hr;

use App\Enums\LeaveStatus;
use App\Enums\LeaveType;
use App\Filament\Resources\Employees\Pages\ListEmployees;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Services\TenantRoleProvisioner;
use Livewire\Livewire;

/** A κανονική άδεια crossing New Year is charged to each year by its working days. */
class LeaveYearSplitTest extends HrTestCase
{
    private function approved(Employee $e, string $from, string $to, int $days): LeaveRequest
    {
        return LeaveRequest::create(['company_id' => $this->company->id, 'employee_id' => $e->id, 'type' => LeaveType::Annual,
            'starts_on' => $from, 'ends_on' => $to, 'days' => $days, 'status' => LeaveStatus::Approved]);
    }

    public function test_leave_crossing_new_year_is_split_by_working_days(): void
    {
        $e = $this->employeeFor(null);
        // Τρ 29/12 – Τρ 5/1: 29-31/12 = 3 εργάσιμες· 1/1 αργία, 2-3/1 Σ/Κ, 4-5/1 = 2.
        $this->approved($e, '2026-12-29', '2027-01-05', 5);
        $this->approved($e, '2026-10-05', '2026-10-09', 5);   // plain, inside 2026

        $this->assertSame(8, $e->annualLeaveTaken(2026));
        $this->assertSame(2, $e->annualLeaveTaken(2027));
        $this->assertSame(0, $e->annualLeaveTaken(2025));
        $this->assertSame([$e->id => 8], Employee::annualLeaveTakenMap($this->company->id, 2026));
        $this->assertSame([$e->id => 2], Employee::annualLeaveTakenMap($this->company->id, 2027));
    }

    public function test_hand_adjusted_days_still_add_up_across_the_two_years(): void
    {
        $e = $this->employeeFor(null);
        $this->approved($e, '2026-12-29', '2027-01-05', 4);   // approver changed 5 → 4

        $this->assertSame(4, $e->annualLeaveTaken(2026) + $e->annualLeaveTaken(2027));
        $this->assertSame([2, 2], [$e->annualLeaveTaken(2026), $e->annualLeaveTaken(2027)]);   // round(4·3/5)=2
    }

    public function test_pending_and_other_types_are_not_charged(): void
    {
        $e = $this->employeeFor(null);
        LeaveRequest::create(['company_id' => $this->company->id, 'employee_id' => $e->id, 'type' => LeaveType::Annual,
            'starts_on' => '2026-12-29', 'ends_on' => '2027-01-05', 'days' => 5, 'status' => LeaveStatus::Pending]);
        LeaveRequest::create(['company_id' => $this->company->id, 'employee_id' => $e->id, 'type' => LeaveType::Sick,
            'starts_on' => '2026-12-29', 'ends_on' => '2027-01-05', 'days' => 5, 'status' => LeaveStatus::Approved]);

        $this->assertSame([0, 0], [$e->annualLeaveTaken(2026), $e->annualLeaveTaken(2027)]);
    }

    public function test_employee_list_shows_the_split_balance(): void
    {
        $this->travelTo('2027-01-10 10:00:00');
        $this->actAs($this->makeUser(TenantRoleProvisioner::ROLE_COMPANY_ADMIN));
        $e = $this->employeeFor(null);
        $e->forceFill(['annual_leave_days' => 20])->save();
        $this->approved($e, '2026-12-29', '2027-01-05', 5);

        Livewire::test(ListEmployees::class)->assertSee('18 / 20');   // only 2 of the 5 days are 2027's
    }
}
