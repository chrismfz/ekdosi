<?php

namespace App\Filament\Resources\PaymentGatewayConnections\Pages;

use App\Contracts\HasSecretConfig;
use App\Filament\Resources\PaymentGatewayConnections\PaymentGatewayConnectionResource;
use App\Services\Payments\PaymentGatewayRegistry;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPaymentGatewayConnection extends EditRecord
{
    protected static string $resource = PaymentGatewayConnectionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    /**
     * Write-only secrets: NEVER load a stored secret back into the form (so it
     * can't be read from the browser). The field shows blank; leaving it blank on
     * save keeps the existing value (see mutateFormDataBeforeSave).
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        foreach ($this->secretKeys() as $key) {
            if (isset($data['config'][$key])) {
                $data['config'][$key] = null;
            }
        }

        return $data;
    }

    /**
     * A blank secret field means «unchanged» — restore the stored value so editing
     * other fields (label, active, endpoint) can't wipe the secret. A typed value
     * overwrites as normal.
     */
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

    /** @return list<string> the gateway's write-only config keys (none if it has no secrets). */
    private function secretKeys(): array
    {
        $gateway = app(PaymentGatewayRegistry::class)->for((string) $this->record->gateway);

        return $gateway instanceof HasSecretConfig ? $gateway->secretConfigKeys() : [];
    }
}
