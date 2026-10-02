<?php

namespace App\Filament\Resources\ProductAttributes\Pages;

use App\Filament\Resources\ProductAttributes\ProductAttributeResource;
use App\Filament\Support\GuardedDeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\DB;

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
     * A value removed from the repeater is deleted on save; one still used by
     * variants would hit the restrictOnDelete FK as a raw DB error — refuse it
     * with a readable message instead.
     */
    protected function beforeSave(): void
    {
        $kept = collect(array_keys($this->form->getRawState()['values'] ?? []))
            ->map(fn ($key) => (int) str_replace('record-', '', (string) $key))
            ->filter()
            ->all();

        $removedInUse = $this->getRecord()->values()
            ->whereKeyNot($kept)
            ->whereExists(fn ($q) => $q->from('product_variant_values')
                ->whereColumn('product_variant_values.product_attribute_value_id', 'product_attribute_values.id'))
            ->pluck('value');

        if ($removedInUse->isNotEmpty()) {
            Notification::make()
                ->title('Δεν αφαιρούνται τιμές που χρησιμοποιούνται')
                ->body('«'.$removedInUse->implode('», «').'» — υπάρχουν παραλλαγές με αυτές τις τιμές. Διάγραψε πρώτα τις παραλλαγές.')
                ->danger()
                ->persistent()
                ->send();

            $this->halt();
        }
    }
}
