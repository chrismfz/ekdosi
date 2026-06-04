<?php

namespace App\Filament\Resources\Companies\Pages;

use App\Filament\Resources\Companies\CompanyResource;
use App\Filament\Support\SendChannelFormBridge;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCompany extends EditRecord
{
    protected static string $resource = CompanyResource::class;

    /** Inject the synthetic «Τρόπος αποστολής» + provider-cred fields from the record (P3). */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        return SendChannelFormBridge::hydrate($data, $this->record);
    }

    /** Decompose the synthetic fields back into the real columns + encrypted config (P3). */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return SendChannelFormBridge::dehydrate($data, $this->record);
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
