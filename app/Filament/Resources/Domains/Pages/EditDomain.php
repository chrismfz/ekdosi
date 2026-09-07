<?php

namespace App\Filament\Resources\Domains\Pages;

use App\Filament\Resources\Domains\DomainResource;
use App\Models\Domain;
use App\Models\DomainTld;
use Filament\Facades\Filament;
use Filament\Resources\Pages\EditRecord;

class EditDomain extends EditRecord
{
    protected static string $resource = DomainResource::class;

    /** Keep tld/fqdn derived when sld or the catalogue TLD changes. */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $tld = DomainTld::query()
            ->where('company_id', Filament::getTenant()?->getKey())
            ->find($data['domain_tld_id'] ?? $this->record->domain_tld_id);
        $sld = mb_strtolower(trim((string) ($data['sld'] ?? $this->record->sld)));
        $data['sld'] = $sld;
        $data['tld'] = (string) $tld?->tld;
        $data['fqdn'] = Domain::fqdnFor($sld, (string) $tld?->tld);

        return $data;
    }
}
