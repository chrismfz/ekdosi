<?php

namespace App\Filament\Resources\Leads\Pages;

use App\Enums\LeadStatus;
use App\Filament\BaseListRecords;
use App\Filament\Pages\LeadsBoard;
use App\Filament\Pages\LeadsCalendar;
use App\Filament\Resources\Leads\LeadResource;
use App\Models\Lead;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListLeads extends BaseListRecords
{
    protected static string $resource = LeadResource::class;

    /** Days without any timeline row before an open lead counts as «αδρανές». */
    public const STALE_DAYS = 14;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('board')
                ->label('Πίνακας')
                ->icon('heroicon-o-view-columns')
                ->color('gray')
                ->visible(fn (): bool => LeadsBoard::canAccess())
                ->url(LeadsBoard::getUrl()),
            Action::make('calendar')
                ->label('Ημερολόγιο')
                ->icon('heroicon-o-calendar-days')
                ->color('gray')
                ->visible(fn (): bool => LeadsCalendar::canAccess())
                ->url(LeadsCalendar::getUrl()),
            CreateAction::make()->label('Νέο lead'),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'open';
    }

    /**
     * Status-driven work tabs. Badge counts are per tenant.
     */
    public function getTabs(): array
    {
        return [
            'open' => Tab::make('Ανοιχτά')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->open())
                ->badge(fn (): int => $this->count(fn (Builder $q) => $q->open())),

            'new' => Tab::make('Νέα')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', LeadStatus::New->value))
                ->badge(fn (): int => $this->count(fn (Builder $q) => $q->where('status', LeadStatus::New->value))),

            'due' => Tab::make('Για σήμερα')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->due())
                ->badge(fn (): int => $this->count(fn (Builder $q) => $q->due()))
                ->badgeColor('warning'),

            'overdue' => Tab::make('Ληξιπρόθεσμα')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->overdue())
                ->badge(fn (): int => $this->count(fn (Builder $q) => $q->overdue()))
                ->badgeColor('danger'),

            'stale' => Tab::make('Αδρανή')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->stale(self::STALE_DAYS))
                ->badge(fn (): int => $this->count(fn (Builder $q) => $q->stale(self::STALE_DAYS)))
                ->badgeColor('warning'),

            'won' => Tab::make('Πελάτες')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', LeadStatus::Won->value)),

            'closed' => Tab::make('Χαμένα / Όχι')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereIn('status', [
                    LeadStatus::Lost->value, LeadStatus::DoNotContact->value,
                ])),

            'all' => Tab::make('Όλα'),
        ];
    }

    /**
     * @param  callable(Builder): Builder  $scope
     */
    private function count(callable $scope): int
    {
        $tenantId = Filament::getTenant()?->getKey();
        if ($tenantId === null) {
            return 0;
        }

        return (int) $scope(Lead::query()->where('company_id', $tenantId))->count();
    }
}
