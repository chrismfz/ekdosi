<?php

namespace App\Filament\Resources\Leads\Pages;

use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Filament\Resources\Leads\LeadResource;
use App\Services\Leads\LeadMatcher;
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

        // A lead that matches an existing customer is an upsell/repeat contact:
        // record the source as such unless the operator chose one (design §4).
        if (blank($data['source'] ?? null) && $data['company_id'] !== null) {
            $match = app(LeadMatcher::class)->find(
                (int) $data['company_id'],
                $data['afm'] ?? null,
                $data['email'] ?? null,
                [$data['phone'] ?? null, $data['mobile'] ?? null],
            );
            if ($match->customers->isNotEmpty()) {
                $data['source'] = LeadSource::ExistingCustomer->value;
            }
        }

        return $data;
    }

    /** Land on the edit page so the operator can log the first contact right away. */
    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
