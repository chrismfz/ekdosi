<?php

namespace App\Filament\Resources\DeliveryNotes\Pages;

use App\Filament\Resources\DeliveryNotes\DeliveryNoteResource;
use App\Models\DeliveryNote;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Enums\Width;
use Illuminate\Database\Eloquent\Model;

/**
 * Edit a Δελτίο Αποστολής — allowed ONLY while it's a draft (no mydata_state).
 * Once filed at myDATA the note is legally frozen; canEdit blocks the page so
 * the «Επεξεργασία» link doesn't even appear.
 */
class EditDeliveryNote extends EditRecord
{
    protected static string $resource = DeliveryNoteResource::class;

    public function getMaxContentWidth(): Width
    {
        return Width::Full;
    }

    public static function canEdit(Model $record): bool
    {
        return $record instanceof DeliveryNote
            && $record->mydata_state === null
            && $record->local_status === 'draft';
    }
}
