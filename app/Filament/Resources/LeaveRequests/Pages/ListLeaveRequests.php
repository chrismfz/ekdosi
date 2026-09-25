<?php

namespace App\Filament\Resources\LeaveRequests\Pages;

use App\Enums\LeaveStatus;
use App\Filament\BaseListRecords;
use App\Filament\Pages\LeaveCalendar;
use App\Filament\Resources\LeaveRequests\LeaveRequestResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListLeaveRequests extends BaseListRecords
{
    protected static string $resource = LeaveRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('calendar')
                ->label('Ημερολόγιο')
                ->icon('heroicon-o-calendar')
                ->color('gray')
                ->visible(fn (): bool => LeaveCalendar::canAccess())
                ->url(fn (): string => LeaveCalendar::getUrl()),
            CreateAction::make()->label('Νέο αίτημα άδειας'),
        ];
    }

    public function getTabs(): array
    {
        $status = fn (LeaveStatus $s) => fn (Builder $query): Builder => $query->where('status', $s->value);

        return [
            'pending' => Tab::make('Σε αναμονή')
                ->modifyQueryUsing($status(LeaveStatus::Pending))
                ->badge(fn (): int => LeaveRequestResource::getEloquentQuery()->where('status', LeaveStatus::Pending->value)->count())
                ->badgeColor('warning'),
            'upcoming' => Tab::make('Εγκεκριμένες (τρέχουσες/επόμενες)')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->where('status', LeaveStatus::Approved->value)
                    ->whereDate('ends_on', '>=', now()->toDateString())),
            'all' => Tab::make('Όλες'),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'pending';
    }
}
