<?php

namespace App\Filament\Resources\Leads\Pages;

use App\Enums\LeadStatus;
use App\Filament\Resources\Leads\LeadResource;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;

class CreateLead extends CreateRecord
{
    protected static string $resource = LeadResource::class;

    /**
     * Stamp the tenant explicitly (robust in CLI/test paths where Filament's
     * tenancy observer isn't booted — same as CreateTag / CreateQuote) and
     * keep `lost_reason` empty unless the status actually needs one.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['company_id'] ??= Filament::getTenant()?->getKey();

        if (! (LeadStatus::tryFrom((string) ($data['status'] ?? ''))?->requiresReason() ?? false)) {
            $data['lost_reason'] = null;
        }

        return $data;
    }

    /** Land on the edit page so the operator can log the first contact right away. */
    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
