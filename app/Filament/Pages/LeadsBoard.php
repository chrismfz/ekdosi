<?php

namespace App\Filament\Pages;

use App\Enums\LeadStatus;
use App\Filament\Resources\Leads\LeadResource;
use App\Models\Company;
use App\Models\Lead;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DateTimePicker;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;

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
 * Read gate View:LeadsBoard; moving needs Update:Lead.
 */
class LeadsBoard extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedViewColumns;

    protected static ?string $navigationLabel = 'Πίνακας leads';

    protected static ?int $navigationSort = 0;

    protected string $view = 'filament.pages.leads-board';

    /** Soft cap so a board never renders thousands of cards. */
    public const MAX_CARDS = 400;

    /** Operator filter: '' = everyone, 'me', or a user id. */
    public string $operator = '';

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

    public function canMove(): bool
    {
        return Gate::allows('Update:Lead');
    }

    /** @return array<int, string> */
    public function getOperatorOptions(): array
    {
        /** @var Company $tenant */
        $tenant = Filament::getTenant();

        return $tenant->users()->orderBy('name')->pluck('users.name', 'users.id')->all();
    }

    /**
     * Open leads of the tenant grouped by status value (only board columns),
     * next step first (nulls last), then name.
     *
     * @return array<string, Collection<int, Lead>>
     */
    public function getCards(): array
    {
        /** @var Company $tenant */
        $tenant = Filament::getTenant();

        $leads = Lead::query()
            ->where('company_id', $tenant->id)
            ->whereIn('status', array_map(fn (LeadStatus $s): string => $s->value, self::columns()))
            ->when($this->operator === 'me', fn ($q) => $q->where('assigned_user_id', auth()->id()))
            ->when(ctype_digit($this->operator), fn ($q) => $q->where('assigned_user_id', (int) $this->operator))
            ->with('assignedTo:id,name')
            ->orderByRaw('next_action_at IS NULL')
            ->orderBy('next_action_at')
            ->orderBy('name')
            ->limit(self::MAX_CARDS)
            ->get();

        $grouped = array_fill_keys(array_map(fn (LeadStatus $s): string => $s->value, self::columns()), new Collection);
        foreach ($leads->groupBy(fn (Lead $l): string => $l->status->value) as $status => $group) {
            $grouped[$status] = $group->values();
        }

        return $grouped;
    }

    public function isCapped(): bool
    {
        return array_sum(array_map(fn (Collection $c): int => $c->count(), $this->getCards())) >= self::MAX_CARDS;
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

        $lead->update(['status' => $target, 'lost_reason' => null]);

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
                    ->required()
                    ->default(fn () => now()->addWeek()->setTime(10, 0)),
            ])
            ->action(function (array $arguments, array $data): void {
                $lead = $this->movableLead((int) ($arguments['lead'] ?? 0));
                if ($lead === null) {
                    return;
                }

                $lead->update([
                    'status' => LeadStatus::NotNow,
                    'lost_reason' => null,
                    'next_action_at' => $data['next_action_at'],
                ]);

                Notification::make()
                    ->title($lead->name.' → '.LeadStatus::NotNow->getLabel())
                    ->success()
                    ->send();
            });
    }

    /**
     * The lead a drop may move: this tenant's, open, not trashed — and the
     * operator may update leads. Anything else is refused with a notice.
     */
    private function movableLead(int $leadId): ?Lead
    {
        if (! $this->canMove()) {
            $this->fail('Δεν έχεις δικαίωμα να αλλάζεις leads.');

            return null;
        }

        /** @var Company $tenant */
        $tenant = Filament::getTenant();
        $lead = Lead::query()->where('company_id', $tenant->id)->whereKey($leadId)->first();

        if ($lead === null || ! $lead->isOpen()) {
            $this->fail('Το lead δεν είναι ανοιχτό (ή δεν υπάρχει πια).');

            return null;
        }

        return $lead;
    }

    private function fail(string $message): void
    {
        Notification::make()->title($message)->danger()->send();
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
