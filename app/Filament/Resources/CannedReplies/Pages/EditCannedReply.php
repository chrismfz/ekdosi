<?php

namespace App\Filament\Resources\CannedReplies\Pages;

use App\Filament\Resources\CannedReplies\CannedReplyResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCannedReply extends EditRecord
{
    protected static string $resource = CannedReplyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
