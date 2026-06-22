<?php

namespace App\Filament\Resources\Cmr\Pages;

use App\Filament\Resources\Cmr\CmrResource;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use RuntimeException;

/**
 * Create a STANDALONE CMR (no source document) — for third-party goods passing
 * through us. The per-company `number` is allocated in CmrNote::creating; here we
 * only bind the tenant (BelongsToCompany does not auto-fill company_id).
 */
class CreateCmr extends CreateRecord
{
    protected static string $resource = CmrResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $tenant = Filament::getTenant();
        if (! $tenant) {
            throw new RuntimeException('Cannot create a CMR without a tenant context.');
        }
        $data['company_id'] = $tenant->getKey();

        return $data;
    }
}
