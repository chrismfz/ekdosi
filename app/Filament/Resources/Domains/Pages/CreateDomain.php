<?php

namespace App\Filament\Resources\Domains\Pages;

use App\Filament\Resources\Domains\DomainResource;
use App\Models\Domain;
use App\Models\DomainTld;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Validation\ValidationException;

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

        // Friendly uniqueness (incl. soft-deleted tombstones) instead of a raw
        // QueryException from the unique(company_id, fqdn) constraint.
        $taken = Domain::query()
            ->withTrashed()
            ->where('company_id', $data['company_id'])
            ->where('fqdn', $data['fqdn'])
            ->exists();
        if ($taken) {
            throw ValidationException::withMessages([
                'data.sld' => 'Το '.$data['fqdn'].' υπάρχει ήδη στο χαρτοφυλάκιο (ίσως διαγραμμένο).',
            ]);
        }

        return $data;
    }
}
