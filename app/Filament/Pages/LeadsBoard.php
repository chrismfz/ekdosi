<?php

namespace App\Filament\Pages;

use App\Enums\LeadStatus;
use App\Filament\Pages\Concerns\InteractsWithLeadViews;
use App\Filament\Resources\Leads\LeadResource;
use App\Models\Company;
use App\Models\Lead;
use BackedEnum;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DateTimePicker;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use UnitEnum;

/**
 * «Πίνακας leads» — Leads L3 kanban: one column per OPEN status, a card per
 * lead, drag a card to move it. No JS library: Alpine (bundled with the
 * panel) + HTML5 drag events; the drop calls moveLead() and the model's
 * status hook writes the «Αλλαγή κατάστασης» timeline row exactly like the
 * modal does. The board keeps the same rules as that modal:
 *   - Won is never a column (only ConvertLeadToCustomer writes it);
 *   - Lost / «Μην ξαναενοχλήσετε» are not columns either (they need a reason
 *     and, for DNC, an explicit confirmation — use the lead's action);
 *   - «Όχι τώρα» needs a date, so that drop opens a small modal (notNow).
 * Read gate View:LeadsBoard; moving needs Update:Lead. The transition itself
 * is Lead::changeStatus — the one definition shared with the modal.
 */
class LeadsBoard extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedViewColumns;

    protected static string|UnitEnum|null $navigationGroup = 'Leads';

    protected static ?string $navigationLabel = 'Πίνακας leads';

    protected static ?int $navigationSort = 20;

    protected string $view = 'filament.pages.leads-board';

    use InteractsWithLeadViews;

    /** Cards rendered per column; the header badge always shows the TRUE count. */
    public const MAX_PER_COLUMN = 100;

    /** @var array<string, Collection<int, Lead>>|null */
    private ?array $cards = null;

    /** @var array<string, int> status => true count */
    private array $columnCounts = [];

    /** @return list<LeadStatus> the columns, in funnel order */
    public static function columns(): array
    {
        return [LeadStatus::New, LeadStatus::Contacted, LeadStatus::Interested, LeadStatus::Quoted, LeadStatus::NotNow];
    }

    public function getTitle(): string
    {
        return 'Πίνακας leads';
    }

    public static function canAccess(): bool
    {
        return Filament::getTenant() instanceof Company
            && (bool) auth()->user()?->can('View:LeadsBoard');
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    /**
     * Open leads of the tenant grouped by status value (only board columns),
     * next step first (nulls last), then name — at most MAX_PER_COLUMN cards
     * per column (the badge keeps the true count). Memoised per request.
     *
     * @return array<string, Collection<int, Lead>>
     */
    public function getCards(): array
    {
        if ($this->cards !== null) {
            return $this->cards;
        }

        /** @var Company $tenant */
        $tenant = Filament::getTenant();

        $leads = Lead::query()
            ->where('company_id', $tenant->id)
            ->whereIn('status', array_map(fn (LeadStatus $s): string => $s->value, self::columns()))
            ->forOperator($this->operator)
            ->with('assignedTo:id,name')
            ->orderByRaw('next_action_at IS NULL')
            ->orderBy('next_action_at')
            ->orderBy('name')
            ->get();

        $grouped = array_fill_keys(array_map(fn (LeadStatus $s): string => $s->value, self::columns()), new Collection);
        $this->columnCounts = array_fill_keys(array_keys($grouped), 0);
        foreach ($leads->groupBy(fn (Lead $l): string => $l->status->value) as $status => $group) {
            $this->columnCounts[$status] = $group->count();
            $grouped[$status] = $group->take(self::MAX_PER_COLUMN)->values();
        }

        return $this->cards = $grouped;
    }

    /** True number of leads in a column (the cards may be capped). */
    public function columnCount(string $status): int
    {
        $this->getCards();

        return $this->columnCounts[$status] ?? 0;
    }

    public function isCapped(): bool
    {
        $this->getCards();

        return max($this->columnCounts ?: [0]) > self::MAX_PER_COLUMN;
    }

    /**
     * Drop handler: move a lead to a board column. Same effect as the
     * «Αλλαγή κατάστασης» modal for the plain columns; «Όχι τώρα» goes
     * through notNowAction (needs a date) and is refused here.
     */
    public function moveLead(int $leadId, string $status): void
    {
        $target = LeadStatus::tryFrom($status);
        if ($target === null || ! in_array($target, self::columns(), true) || $target === LeadStatus::NotNow) {
            $this->fail('Μη έγκυρη στήλη.');

            return;
        }

        $lead = $this->movableLead($leadId);
        if ($lead === null) {
            return;
        }
        if ($lead->status === $target) {
            return;
        }

        $lead->changeStatus($target);

        Notification::make()
            ->title($lead->name.' → '.$target->getLabel())
            ->success()
            ->send();
    }

    /** The «Όχι τώρα» drop: needs «ξαναδές το στις» — a modal, then the move. */
    public function notNowAction(): Action
    {
        return Action::make('notNow')
            ->label('Όχι τώρα')
            ->modalHeading('Όχι τώρα — ξαναδές το στις')
            ->modalSubmitActionLabel('Αποθήκευση')
            ->schema([
                DateTimePicker::make('next_action_at')
                    ->label('Ξαναδές το στις')
                    ->seconds(false)
                    ->required(),
            ])
            // Like the modal on the lead: the lead's own date first (a slip-drop
            // inside «Όχι τώρα» + Save must not silently replace a deliberately
            // chosen date), else next week.
            ->fillForm(function (array $arguments): array {
                /** @var Company $tenant */
                $tenant = Filament::getTenant();
                $current = Lead::query()->where('company_id', $tenant->id)->whereKey((int) ($arguments['lead'] ?? 0))->value('next_action_at');

                return ['next_action_at' => $current ?? now()->addWeek()->setTime(10, 0)];
            })
            ->action(function (array $arguments, array $data): void {
                $lead = $this->movableLead((int) ($arguments['lead'] ?? 0));
                if ($lead === null) {
                    return;
                }

                $lead->changeStatus(LeadStatus::NotNow, null, Carbon::parse($data['next_action_at']));

                Notification::make()
                    ->title($lead->name.' → '.LeadStatus::NotNow->getLabel())
                    ->success()
                    ->send();
            });
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('list')
                ->label('Λίστα')
                ->icon('heroicon-o-list-bullet')
                ->color('gray')
                ->url(LeadResource::getUrl('index')),
            Action::make('calendar')
                ->label('Ημερολόγιο')
                ->icon('heroicon-o-calendar-days')
                ->color('gray')
                ->visible(fn (): bool => LeadsCalendar::canAccess())
                ->url(LeadsCalendar::getUrl()),
        ];
    }
}
