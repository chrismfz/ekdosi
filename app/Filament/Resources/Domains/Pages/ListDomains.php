<?php

namespace App\Filament\Resources\Domains\Pages;

use App\Enums\DomainStatus;
use App\Filament\BaseListRecords;
use App\Filament\Resources\Domains\DomainResource;
use App\Models\Domain;
use Filament\Actions\CreateAction;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class ListDomains extends BaseListRecords
{
    protected static string $resource = DomainResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Καταχώρηση domain'),
        ];
    }

    /**
     * Work tabs. NOTE: the modifyQueryUsing closure parameter MUST be named
     * `$query` (Filament injects by argument name — the ListTickets lesson).
     */
    public function getTabs(): array
    {
        $soon = fn (): Carbon => Carbon::today()->addDays(45);

        return [
            'active' => Tab::make('Ενεργά')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', DomainStatus::Active->value)),
            'expiring' => Tab::make('Λήγουν σύντομα')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->where('status', DomainStatus::Active->value)
                    ->whereNotNull('expires_at')
                    ->where('expires_at', '<=', $soon()))
                ->badge(fn (): int => Domain::query()
                    ->where('status', DomainStatus::Active->value)
                    ->whereNotNull('expires_at')
                    ->where('expires_at', '<=', $soon())
                    ->count())
                ->badgeColor('warning'),
            'unassigned' => Tab::make('Χωρίς πελάτη')
                // Only ASSIGNABLE rows — a terminal-status stray would ring a
                // worklist whose action always refuses it.
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->assignable())
                ->badge(fn (): int => Domain::query()->assignable()->count())
                ->badgeColor('warning'),
            'all' => Tab::make('Όλα'),
        ];
    }
}
