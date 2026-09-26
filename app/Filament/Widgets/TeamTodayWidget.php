<?php

namespace App\Filament\Widgets;

use App\Enums\LeaveStatus;
use App\Filament\Pages\LeaveCalendar;
use App\Filament\Resources\LeaveRequests\LeaveRequestResource;
use App\Models\Company;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\WorkCardEvent;
use App\Policies\LeaveRequestPolicy;
use App\Services\Ergani\WorkCardService;
use App\Support\Hr\WorkingDays;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;

/**
 * «Η ομάδα σήμερα» — the everyday question at a glance: who is away today and
 * later this week, how many requests wait for approval, and (when the card is
 * used) who is in right now. Colleagues see WHO is away, never the leave type
 * or pending requests (same privacy rule as the leave calendar).
 */
class TeamTodayWidget extends Widget
{
    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.team-today';

    public static function canView(): bool
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Company
            && $tenant->hasErgani()
            && (bool) auth()->user()?->can('View:LeaveCalendar');
    }

    protected function getViewData(): array
    {
        /** @var Company $c */
        $c = Filament::getTenant();
        $approver = LeaveRequestPolicy::isApprover(auth()->user());
        $today = CarbonImmutable::today();
        $weekEnd = $today->addDays(7);   // «next 7 days» — on a Friday «this week» would be only the weekend

        $leaves = LeaveRequest::query()
            ->where('company_id', $c->getKey())
            ->where('status', LeaveStatus::Approved->value)
            ->whereDate('starts_on', '<=', $weekEnd->toDateString())
            ->whereDate('ends_on', '>=', $today->toDateString())
            ->with(['employee' => fn ($q) => $q->withTrashed()])   // a deleted employee's leave still shows a name
            ->orderBy('starts_on')
            ->get();

        $label = fn (LeaveRequest $l): string => $l->employee?->full_name
            .($approver ? ' · '.$l->type?->getLabel() : '')
            .' (έως '.$l->ends_on->format('d/m').')';

        $awayToday = $leaves->filter(fn (LeaveRequest $l): bool => $l->starts_on->lte($today))->map($label)->values()->all();
        $laterThisWeek = $leaves->filter(fn (LeaveRequest $l): bool => $l->starts_on->gt($today))
            ->map(fn (LeaveRequest $l): string => $l->starts_on->locale('el')->isoFormat('dd D/M').': '.$label($l))->values()->all();

        $pending = $approver
            ? LeaveRequest::query()->where('company_id', $c->getKey())->where('status', LeaveStatus::Pending->value)->count()
            : 0;

        // Presence only where the card is actually in use (a punch in the last shift window).
        $presence = null;
        if ($approver && WorkCardEvent::query()->where('company_id', $c->getKey())
            ->where('occurred_at', '>=', now()->subHours(WorkCardService::OPEN_SHIFT_HOURS))->exists()) {
            $board = collect(app(WorkCardService::class)->presence($c));
            // Denominator = people who use the card (if any are marked), not every employee.
            $cardUsers = Employee::query()->where('company_id', $c->getKey())->where('is_active', true)->where('has_work_card', true)->count();
            $presence = [
                'in' => $board->where('in', true)->map(fn (array $e): string => $e['name'].' ('.$e['since'].')')->values()->all(),
                'total' => $cardUsers > 0 ? $cardUsers : $board->count(),
            ];
        }

        return [
            'holiday' => WorkingDays::for((int) $c->getKey())->holidayName($today),
            'weekend' => $today->isWeekend(),
            'awayToday' => $awayToday,
            'laterThisWeek' => $laterThisWeek,
            'approver' => $approver,
            'pending' => $pending,
            'pendingUrl' => $pending > 0 ? LeaveRequestResource::getUrl('index', ['tab' => 'pending']) : null,
            'calendarUrl' => LeaveCalendar::canAccess() ? LeaveCalendar::getUrl() : null,
            'presence' => $presence,
        ];
    }
}
