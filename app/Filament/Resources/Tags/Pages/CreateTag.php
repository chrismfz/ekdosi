<?php

namespace App\Filament\Resources\Tags\Pages;

use App\Filament\Resources\Tags\TagResource;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;

class CreateTag extends CreateRecord
{
    protected static string $resource = TagResource::class;

    /**
     * Stamp the tenant explicitly. The BelongsToCompany scope is read-only
     * (no company_id auto-fill on create), so we set it here rather than rely
     * on Filament's tenancy association alone — robust in CLI/test paths too.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['company_id'] ??= Filament::getTenant()?->getKey();

        return $data;
    }
}
