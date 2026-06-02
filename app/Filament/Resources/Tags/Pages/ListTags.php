<?php

namespace App\Filament\Resources\Tags\Pages;

use App\Filament\BaseListRecords;
use App\Filament\Resources\Tags\TagResource;
use Filament\Actions\CreateAction;

class ListTags extends BaseListRecords
{
    protected static string $resource = TagResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
