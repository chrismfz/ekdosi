<?php

namespace App\Filament\Resources\Domains\Pages;

use App\Filament\Resources\Domains\DomainResource;
use App\Models\Domain;
use App\Models\DomainTld;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;

class CreateDomain extends CreateRecord
{
    protected static string $resource = DomainResource::class;

    /**
     * Explicit tenant stamp (the CLAUDE.md tenancy rule) + the derived columns:
     * the authoritative name is sld + the catalogue TLD; `tld`/`fqdn` are copies
     * kept for lookups and the per-tenant unique.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['company_id'] = Filament::getTenant()?->getKey();

        $tld = DomainTld::query()
            ->where('company_id', $data['company_id'])
            ->find($data['domain_tld_id'] ?? null);
        $data['sld'] = mb_strtolower(trim((string) ($data['sld'] ?? '')));
        $data['tld'] = (string) $tld?->tld;
        $data['fqdn'] = Domain::fqdnFor($data['sld'], (string) $tld?->tld);

        return $data;
    }
}
