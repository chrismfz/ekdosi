<?php

namespace App\Filament\Resources\Expenses\Pages;

use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\Expenses\Support\PersistsExpenseLines;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Edit a MANUAL expense (the resource gates `canEdit` to source=manual — a
 * myDATA-sourced doc isn't hand-edited). Loads the lines into the repeater and
 * re-syncs them on save, recomputing header totals.
 */
class EditExpense extends EditRecord
{
    use PersistsExpenseLines;

    protected static string $resource = ExpenseResource::class;

    /** Hydrate the plain `lines` repeater from the relation. */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['lines'] = $this->record->lines()
            ->orderBy('line_number')
            ->get(['item_descr', 'quantity', 'vat_category', 'net_value', 'vat_amount'])
            ->map(fn ($l) => $l->only(['item_descr', 'quantity', 'vat_category', 'net_value', 'vat_amount']))
            ->all();

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $companyId = $record->company_id;
        [$header, $lines] = $this->splitExpenseData($data, $companyId);

        $record->update($header);
        $this->syncExpenseLines($record, $lines, $companyId);

        return $record;
    }
}
