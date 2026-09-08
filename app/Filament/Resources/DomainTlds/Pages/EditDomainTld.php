<?php

namespace App\Filament\Resources\DomainTlds\Pages;

use App\Filament\Resources\DomainTlds\DomainTldResource;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;

class EditDomainTld extends EditRecord
{
    protected static string $resource = DomainTldResource::class;

    /**
     * SERVER-side rename lock (the form's disabled() is client-side only —
     * Filament still dehydrates a disabled field's state, so a crafted
     * Livewire update could rename an in-use TLD and desync every attached
     * domain's stored tld/fqdn). Trashed domains count: the fqdn-uniqueness
     * tombstone check depends on their tld/fqdn staying consistent too.
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $incoming = mb_strtolower(ltrim(trim((string) ($data['tld'] ?? $this->record->tld)), '.'));
        if ($incoming !== $this->record->tld
            && $this->record->domains()->withTrashed()->exists()) {
            throw ValidationException::withMessages([
                'data.tld' => 'Το TLD έχει domains (ή διαγραμμένα tombstones) — δεν μετονομάζεται. Δημιουργήστε νέα εγγραφή.',
            ]);
        }

        return $data;
    }
}
