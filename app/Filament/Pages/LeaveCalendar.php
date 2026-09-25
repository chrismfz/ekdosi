<?php

namespace App\Filament\Pages;

use App\Models\Company;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Policies\LeaveRequestPolicy;
use App\Support\Hr\WorkingDays;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use UnitEnum;

/**
 * «Ημερολόγιο αδειών» — who is off when: one row per employee, one column per
 * day of the month; weekends and holidays (national + company) shaded.
 * Replaces the shared Google Calendar as the place to look.
 *
 * Privacy: approvers see every leave with its kind; other staff see their own
 * in full, colleagues' APPROVED leave only as a neutral «Άδεια» (a sick leave
 * is health data) and never colleagues' pending requests. Read-only.
 */
class LeaveCalendar extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendar;

    protected static string|UnitEnum|null $navigationGroup = 'Προσωπικό';

    protected static ?string $navigationLabel = 'Ημερολόγιο αδειών';

    protected static ?int $navigationSort = 20;

    protected string $view = 'filament.pages.leave-calendar';

    /** The month shown, `Y-m`. */
    public string $month = '';

    public function getTitle(): string
    {
        return 'Ημερολόγιο αδειών';
    }

    public static function canAccess(): bool
    {
        return Filament::getTenant() instanceof Company
            && Filament::getTenant()->hasErgani()
            && (bool) auth()->user()?->can('View:LeaveCalendar');
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function mount(): void
    {
        if (! $this->isValidMonth($this->month)) {
            $this->month = now()->format('Y-m');
        }
    }

    public function updatedMonth(): void
    {
        if (! $this->isValidMonth($this->month)) {
            $this->month = now()->format('Y-m');
        }
    }

    private function isValidMonth(string $value): bool
    {
        return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value) === 1;
    }

    public function previousMonth(): void
    {
        $this->month = $this->monthStart()->subMonthNoOverflow()->format('Y-m');
    }

    public function nextMonth(): void
    {
        $this->month = $this->monthStart()->addMonthNoOverflow()->format('Y-m');
    }

    public function thisMonth(): void
    {
        $this->month = now()->format('Y-m');
    }

    public function monthStart(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('Y-m-d', $this->month.'-01')->startOfDay();
    }

    public function monthLabel(): string
    {
        return $this->monthStart()->locale('el')->isoFormat('MMMM YYYY');
    }

    /**
     * The grid is handed to the view as DATA, never through a public method:
     * a public Livewire method is callable from the browser (`$wire.rows()`) and
     * its return value is serialized back — that leaked colleagues' full
     * Employee records (ΑΦΜ, email, admin notes). rows()/days() are protected and
     * rows carry only what the grid renders.
     *
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return [
            'days' => $this->days(),
            'rows' => $this->rows(),
        ];
    }

    /**
     * @return list<array{date: CarbonImmutable, key: string, weekend: bool, holiday: ?string, today: bool}>
     */
    protected function days(): array
    {
        $tenant = $this->tenant();
        $calendar = WorkingDays::for((int) $tenant->getKey());
        $start = $this->monthStart();
        $today = now()->toDateString();

        $days = [];
        for ($d = $start; $d->month === $start->month; $d = $d->addDay()) {
            $days[] = [
                'date' => $d,
                'key' => $d->toDateString(),
                'weekend' => $d->isWeekend(),
                'holiday' => $calendar->holidayName($d),
                'today' => $d->toDateString() === $today,
            ];
        }

        return $days;
    }

    /**
     * Rows: employees with their visible leave per day.
     *
     * @return list<array{id: int, name: string, entitlement: int, cells: array<string, array{label: string, title: string, class: string}>, remaining: ?int}>
     */
    protected function rows(): array
    {
        $tenant = $this->tenant();
        $from = $this->monthStart();
        $to = $from->endOfMonth();
        $approver = LeaveRequestPolicy::isApprover(auth()->user());
        $me = (int) auth()->id();

        $leaves = LeaveRequest::query()
            ->where('company_id', $tenant->getKey())
            ->active()
            ->overlapping($from, $to)
            ->with('employee')
            ->get()
            ->filter(fn (LeaveRequest $l): bool => $approver
                || $l->isApproved()
                || (int) $l->employee?->user_id === $me);

        $employees = Employee::query()
            ->where('company_id', $tenant->getKey())
            ->where(fn ($q) => $q->where('is_active', true)
                ->orWhereIn('id', $leaves->pluck('employee_id')->unique()->all()))
            ->orderBy('last_name')->orderBy('first_name')
            ->get();

        $byEmployee = $leaves->groupBy('employee_id');
        $taken = Employee::annualLeaveTakenMap((int) $tenant->getKey(), (int) $from->format('Y'));

        return $employees->map(function (Employee $e) use ($byEmployee, $approver, $me, $taken, $from, $to): array {
            $mine = (int) $e->user_id === $me;
            $cells = [];

            /** @var Collection<int, LeaveRequest> $list */
            $list = $byEmployee->get($e->id, collect());
            foreach ($list as $leave) {
                $full = $approver || $mine;
                $pending = $leave->isPending();
                $label = $full ? (string) $leave->type?->shortLabel() : '•';
                $title = ($full ? $leave->type?->getLabel() : 'Άδεια').' · '.$leave->periodLabel()
                    .($pending ? ' (σε αναμονή)' : '');
                $class = $pending ? 'lvc-pending' : 'lvc-'.($full ? ($leave->type?->getColor() ?? 'info') : 'info');

                // Only the visible month — a months-long leave must not walk its whole span.
                $start = CarbonImmutable::parse($leave->starts_on)->max($from);
                $end = CarbonImmutable::parse($leave->ends_on)->min($to);
                for ($d = $start; $d->lte($end); $d = $d->addDay()) {
                    $cells[$d->toDateString()] = ['label' => $label, 'title' => $title, 'class' => $class];
                }
            }

            return [
                'id' => (int) $e->id,
                'name' => $e->full_name,
                'entitlement' => (int) $e->annual_leave_days,
                'cells' => $cells,
                'remaining' => ($approver || $mine) ? $e->annual_leave_days - ($taken[$e->id] ?? 0) : null,
            ];
        })->all();
    }

    private function tenant(): Company
    {
        /** @var Company $tenant */
        $tenant = Filament::getTenant();

        return $tenant;
    }
}
