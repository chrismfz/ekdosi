<?php

namespace App\Filament\Resources\DeliveryNotes\Pages;

use App\Filament\BaseListRecords;
use App\Filament\Resources\DeliveryNotes\DeliveryNoteResource;
use App\Models\DeliveryNote;
use Filament\Actions\CreateAction;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListDeliveryNotes extends BaseListRecords
{
    protected static string $resource = DeliveryNoteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('+ Νέο Δελτίο Αποστολής'),
        ];
    }

    public function getTabs(): array
    {
        $outbox = DeliveryNote::query()->awaitingMyData()->count();

        return [
            'all' => Tab::make('Όλα'),

            // δελτία that should be registered to myDATA but carry no MARK yet.
            'outbox' => Tab::make('Προς υποβολή')
                ->icon('heroicon-o-cloud-arrow-up')
                ->badge($outbox ?: null)
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->awaitingMyData()),
        ];
    }
}
