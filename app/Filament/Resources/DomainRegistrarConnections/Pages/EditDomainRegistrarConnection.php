<?php

namespace App\Filament\Resources\DomainRegistrarConnections\Pages;

use App\Filament\Resources\DomainRegistrarConnections\DomainRegistrarConnectionResource;
use App\Filament\Support\GuardedDeleteAction;
use Filament\Resources\Pages\EditRecord;

/**
 * Write-only secrets (the EditPaymentGatewayConnection pair): a stored secret
 * is NEVER loaded back into the form (can't be read from the browser); a blank
 * secret field on save means «unchanged» and restores the stored value, so
 * editing label/mode/username can't wipe the password.
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

    protected function mutateFormDataBeforeFill(array $data): array
    {
        foreach ($this->secretKeys() as $key) {
            if (isset($data['config'][$key])) {
                $data['config'][$key] = null;
            }
        }

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $stored = $this->record->config ?? [];
        foreach ($this->secretKeys() as $key) {
            if (blank($data['config'][$key] ?? null) && filled($stored[$key] ?? null)) {
                $data['config'][$key] = $stored[$key];
            }
        }

        return $data;
    }

    /** @return list<string> the registrar's secret config keys (from registrar_fields). */
    private function secretKeys(): array
    {
        $fields = config('ekdosi.domains.registrar_fields.'.(string) $this->record->registrar, []);

        return array_keys(array_filter(
            is_array($fields) ? $fields : [],
            fn ($meta): bool => (bool) (($meta['secret'] ?? false)),
        ));
    }
}
