<?php

namespace App\Filament\Resources\Tickets\Pages;

use App\Enums\TicketStatus;
use App\Filament\BaseListRecords;
use App\Filament\Resources\Tickets\TicketResource;
use App\Models\Ticket;
use Filament\Actions\CreateAction;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListTickets extends BaseListRecords
{
    protected static string $resource = TicketResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Νέο αίτημα'),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'queue';
    }

    /**
     * Work tabs. NOTE: the modifyQueryUsing closure parameter MUST be named
     * `$query` — Filament's evaluate() injects the builder by that argument name,
     * so a different name (e.g. `$q`) resolves to null and breaks the query.
     */
    public function getTabs(): array
    {
        $closed = TicketStatus::Closed->value;

        return [
            'queue' => Tab::make('Στην ουρά')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereIn('status', TicketStatus::queueValues()))
                ->badge(fn (): int => Ticket::query()->whereIn('status', TicketStatus::queueValues())->count())
                ->badgeColor('warning'),
            'unassigned' => Tab::make('Χωρίς ανάθεση')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereNull('assigned_to')->where('status', '!=', $closed))
                ->badge(fn (): int => Ticket::query()->whereNull('assigned_to')->where('status', '!=', $closed)->count()),
            'open' => Tab::make('Ανοιχτά')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', '!=', $closed)),
            'all' => Tab::make('Όλα'),
        ];
    }
}
