<?php

namespace App\Filament\Resources\Customers\Pages;

use App\Filament\Concerns\ResolvesAadeFormConflicts;
use App\Filament\Resources\Customers\CustomerResource;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;

class CreateCustomer extends CreateRecord
{
    use ResolvesAadeFormConflicts;

    protected static string $resource = CustomerResource::class;

    /**
     * Stamp the tenant explicitly (robust in CLI/test paths where Filament's
     * tenancy observer isn't booted — same as CreateTag / CreateQuote / CreateLead).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['company_id'] ??= Filament::getTenant()?->getKey();

        return $data;
    }

    /**
     * The form rule already refuses a duplicate ΑΦΜ; this is the race window
     * between that rule and the INSERT (another operator saved the same ΑΦΜ in
     * the same instant). UNIQUE(company_id, afm_key) wins — say so, don't 500.
     */
    protected function handleRecordCreation(array $data): Model
    {
        try {
            return parent::handleRecordCreation($data);
        } catch (UniqueConstraintViolationException) {
            Notification::make()
                ->title('Υπάρχει ήδη πελάτης με αυτό το ΑΦΜ')
                ->body('Δημιουργήθηκε μόλις τώρα από άλλον χειριστή. Βρες τον στη λίστα πελατών αντί να τον ξαναφτιάξεις.')
                ->danger()
                ->persistent()
                ->send();

            throw new Halt;
        }
    }
}
