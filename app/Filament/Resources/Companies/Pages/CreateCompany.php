<?php

namespace App\Filament\Resources\Companies\Pages;

use App\Filament\Resources\Companies\CompanyResource;
use App\Services\MyData\MyDataLookupSeeder;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateCompany extends CreateRecord
{
    protected static string $resource = CompanyResource::class;

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
        if ($this->record->einvoice_provider !== 'gr-mydata') {
            return;
        }

        $seeder = app(MyDataLookupSeeder::class);
        $vat = $seeder->seedVatCategories($this->record);
        $types = $seeder->seedInvoiceTypes($this->record);
        $seeder->seedPaymentMethods($this->record);
        $seeder->seedDistributionAims($this->record);
        $seeder->seedMetricUnits($this->record);
        $seeder->seedDeliveryMethods($this->record);
        $seeder->seedProductCategories($this->record);

        Notification::make()
            ->title('Στήθηκαν τυπικές ρυθμίσεις ΑΑΔΕ')
            ->body("Κατηγορίες ΦΠΑ: {$vat['created']} · Είδη παραστατικών: {$types['created']} (με κατηγοριοποίηση myDATA) · τρόποι πληρωμής/αποστολής, σκοπός διακίνησης, μονάδες & κατηγορίες προϊόντων. Προσαρμόστε τα στο Setup αν χρειάζεται.")
            ->success()
            ->send();
    }
}
