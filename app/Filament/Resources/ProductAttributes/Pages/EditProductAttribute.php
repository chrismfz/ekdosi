<?php

namespace App\Filament\Resources\ProductAttributes\Pages;

use App\Filament\Resources\ProductAttributes\ProductAttributeResource;
use App\Filament\Support\GuardedDeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditProductAttribute extends EditRecord
{
    protected static string $resource = ProductAttributeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            GuardedDeleteAction::make(fn ($record): array => ProductAttributeResource::dependents($record)),
        ];
    }

    /**
     * A value removed from the repeater is deleted on save (and its variant links
     * cascade). Refuse that while LIVE variants still use it — they'd silently
     * lose an axis. Soft-deleted variants don't block (see dependents()).
     */
    protected function beforeSave(): void
    {
        // Existing rows are keyed «record-{id}»; NEW rows by a UUID — which must
        // never be cast to an id ((int) '12ab…' === 12).
        $kept = collect(array_keys($this->form->getRawState()['values'] ?? []))
            ->filter(fn ($key) => str_starts_with((string) $key, 'record-'))
            ->map(fn ($key) => (int) substr((string) $key, 7))
            ->filter()
            ->values()
            ->all();

        $removedInUse = $this->getRecord()->values()
            ->whereKeyNot($kept)
            ->whereExists(fn ($q) => $q->from('product_variant_values')
                ->join('products', 'products.id', '=', 'product_variant_values.product_id')
                ->whereNull('products.deleted_at')
                ->whereColumn('product_variant_values.product_attribute_value_id', 'product_attribute_values.id'))
            ->pluck('value');

        if ($removedInUse->isNotEmpty()) {
            Notification::make()
                ->title('Δεν αφαιρούνται τιμές που χρησιμοποιούνται')
                ->body('«'.$removedInUse->implode('», «').'» — υπάρχουν ενεργές παραλλαγές με αυτές τις τιμές. Διάγραψέ τες πρώτα (καρτέλα «Παραλλαγές» του προϊόντος).')
                ->danger()
                ->persistent()
                ->send();

            $this->halt();
        }
    }
}
