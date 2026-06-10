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
        $data = SendChannelFormBridge::hydrate($data, $this->record);

        // The secret columns are $hidden, so attributesToArray() (Filament's fill
        // source) omits them — re-inject the scalar ones from the record so the
        // admin form prefills exactly as before (the array provider_config is
        // handled above by the bridge's cfg_* fields). Blank-on-save still keeps
        // the stored value via each field's ->dehydrated(filled) rule.
        foreach ($this->record->getHidden() as $column) {
            $value = $this->record->getAttribute($column);
            if (is_scalar($value)) {
                $data[$column] = $value;
            }
        }

        return $data;
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
