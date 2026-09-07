<?php

namespace App\Filament\Resources\DomainRegistrarConnections\Pages;

use App\Filament\Resources\DomainRegistrarConnections\DomainRegistrarConnectionResource;
use App\Filament\Support\GuardedDeleteAction;
use Filament\Resources\Pages\EditRecord;

/**
 * A0 has no credential fields yet, so nothing secret can round-trip through the
 * form. When A2/A4 add per-registrar fields, copy the write-only-secret
 * mutateFormDataBeforeFill/BeforeSave pair from EditPaymentGatewayConnection.
 */
class EditDomainRegistrarConnection extends EditRecord
{
    protected static string $resource = DomainRegistrarConnectionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            GuardedDeleteAction::make(fn ($record): array => DomainRegistrarConnectionResource::dependents($record)),
        ];
    }
}
