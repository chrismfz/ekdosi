<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\InteractsWithLeadViews;
use App\Filament\Resources\Leads\LeadResource;
use App\Models\Company;
use App\Models\Lead;
use BackedEnum;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;

/**
 * «Ημερολόγιο leads» — Leads L3: a month grid (Δευ–Κυρ) of the OPEN leads'
 * «επόμενο βήμα» (next_action_at), overdue ones in red, today highlighted.
 * Drag a lead to another day to reschedule it (keeps the time of day) —
 * Alpine + HTML5 drag, no library. Read gate View:LeadsCalendar; moving
 * needs Update:Lead. Nothing else is written.
 */
class LeadsCalendar extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?string $navigationLabel = 'Ημερολόγιο leads';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.leads-calendar';

    use InteractsWithLeadViews;

    /** The month shown, `Y-m`. */
    public string $month = '';

    public function getTitle(): string
    {
        return 'Ημερολόγιο leads';
    }

    public static function canAccess(): bool
    {
        return Filament::getTenant() instanceof Company
            && (bool) auth()->user()?->can('View:LeadsCalendar');
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function mount(): void
    {
        if ($this->month === '' || ! $this->isValidMonth($this->month)) {
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
     * The grid: full weeks (Monday–Sunday) covering the month.
     *
     * @return list<list<CarbonImmutable>> weeks → days
     */
    public function weeks(): array
    {
        $start = $this->monthStart()->startOfWeek(CarbonImmutable::MONDAY);
        $end = $this->monthStart()->endOfMonth()->endOfWeek(CarbonImmutable::SUNDAY);

        $weeks = [];
        for ($day = $start; $day->lte($end); $day = $day->addWeek()) {
            $week = [];
            for ($i = 0; $i < 7; $i++) {
                $week[] = $day->addDays($i);
            }
            $weeks[] = $week;
        }

        return $weeks;
    }

    /**
     * Open leads with a next step inside the visible grid, keyed by `Y-m-d`.
     *
     * @return array<string, Collection<int, Lead>>
     */
    public function getItems(): array
    {
        /** @var Company $tenant */
        $tenant = Filament::getTenant();
        $weeks = $this->weeks();
        $from = $weeks[0][0]->startOfDay();
        $to = end($weeks)[6]->endOfDay();

        $leads = Lead::query()
            ->where('company_id', $tenant->id)
            ->open()
            ->whereBetween('next_action_at', [$from, $to])
            ->forOperator($this->operator)
            ->with('assignedTo:id,name')
            ->orderBy('next_action_at')
            ->orderBy('name')
            ->get();

        return $leads->groupBy(fn (Lead $l): string => $l->next_action_at->format('Y-m-d'))->all();
    }

    /** Open leads whose step is overdue and BEFORE the visible grid (so they are not lost off-screen). */
    public function overdueBeforeGrid(): int
    {
        /** @var Company $tenant */
        $tenant = Filament::getTenant();
        $from = $this->weeks()[0][0]->startOfDay();

        return Lead::query()
            ->where('company_id', $tenant->id)
            ->overdue()
            ->where('next_action_at', '<', $from)
            ->forOperator($this->operator)
            ->count();
    }

    /** Drop handler: move a lead's next step to another day, keeping its time of day. */
    public function reschedule(int $leadId, string $date): void
    {
        $lead = $this->movableLead($leadId);
        if ($lead === null) {
            return;
        }
        if ($lead->next_action_at === null) {
            $this->fail('Το lead δεν έχει επόμενο βήμα.');

            return;
        }

        // Shape AND calendar validity: createFromFormat rolls «2026-02-31»
        // over to March silently, so compare the round-trip.
        $day = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? Carbon::createFromFormat('Y-m-d', $date) : null;
        if ($day === null || $day->format('Y-m-d') !== $date) {
            $this->fail('Μη έγκυρη ημερομηνία.');

            return;
        }
        $current = $lead->next_action_at;
        $target = $day->setTime((int) $current->format('H'), (int) $current->format('i'));
        if ($target->isSameDay($current)) {
            return;
        }

        // A plain update — the activity log («Ιστορικό») records the change.
        $lead->update(['next_action_at' => $target]);

        Notification::make()
            ->title($lead->name.' → '.$target->format('d/m/Y H:i'))
            ->success()
            ->send();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('list')
                ->label('Λίστα')
                ->icon('heroicon-o-list-bullet')
                ->color('gray')
                ->url(LeadResource::getUrl('index')),
            Action::make('board')
                ->label('Πίνακας')
                ->icon('heroicon-o-view-columns')
                ->color('gray')
                ->visible(fn (): bool => LeadsBoard::canAccess())
                ->url(LeadsBoard::getUrl()),
        ];
    }
}
