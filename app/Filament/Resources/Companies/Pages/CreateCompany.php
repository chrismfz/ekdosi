<?php

namespace App\Filament\Resources\Companies\Pages;

use App\Filament\Resources\Companies\CompanyResource;
use App\Filament\Support\SendChannelFormBridge;
use App\Services\MyData\MyDataLookupSeeder;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateCompany extends CreateRecord
{
    protected static string $resource = CompanyResource::class;

    /**
     * Decompose the flat «Τρόπος αποστολής» + labeled provider creds into the real
     * columns (+ encrypted config) before the record is created (P3).
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return SendChannelFormBridge::dehydrate($data, null);
    }

    /**
     * Fresh-install convenience: a brand-new Greek/myDATA tenant gets the
     * standard AADE lookups pre-installed — §8.2 VAT categories AND the
     * starter invoice types already classified by-the-book (mydata_type +
     * E3 income class + category). So the operator can issue a ΤΠΥ/ΤΙΜ on day
     * one without touching Setup. Idempotent + fill-empty, so it's safe and the
     * «Εισαγωγή τυπικών» buttons stay available to top up later.
     *
     * Gated to gr-mydata tenants: the lookups are Greek-specific, so an
     * Estonian/PEPPOL or «none» tenant is left clean.
     */
    protected function afterCreate(): void
    {
        // Greek lookups for any Greek filing tenant — direct myDATA OR via a provider.
        if (! in_array($this->record->einvoice_provider, ['gr-mydata', 'gr-provider'], true)) {
            return;
        }

        $r = app(MyDataLookupSeeder::class)->seedStandardLookups($this->record);

        Notification::make()
            ->title('Στήθηκαν τυπικές ρυθμίσεις ΑΑΔΕ')
            ->body("Κατηγορίες ΦΠΑ: {$r['vat']['created']} · Είδη παραστατικών: {$r['types']['created']} (με κατηγοριοποίηση myDATA) · τρόποι πληρωμής/αποστολής, σκοπός διακίνησης, μονάδες & κατηγορίες προϊόντων. Προσαρμόστε τα στο Setup αν χρειάζεται.")
            ->success()
            ->send();
    }
}
