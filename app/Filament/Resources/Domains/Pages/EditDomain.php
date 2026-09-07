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

    /**
     * A rename must not orphan the 1:1 ServiceContract's snapshot: its
     * domain/description were stamped with the fqdn at assign time and appear
     * on future renewal invoices (legal documents) — keep the pair consistent.
     */
    protected function afterSave(): void
    {
        $record = $this->record->refresh();
        $contract = $record->serviceContract;
        if ($contract !== null && $contract->domain !== $record->fqdn) {
            $contract->update([
                'domain' => $record->fqdn,
                'description' => 'Ανανέωση domain '.$record->fqdn,
            ]);
        }
    }
}
