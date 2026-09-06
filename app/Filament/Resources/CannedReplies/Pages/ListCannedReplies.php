<?php

namespace App\Filament\Resources\CannedReplies\Pages;

use App\Filament\BaseListRecords;
use App\Filament\Resources\CannedReplies\CannedReplyResource;
use Filament\Actions\CreateAction;

class ListCannedReplies extends BaseListRecords
{
    protected static string $resource = CannedReplyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
