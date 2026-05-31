<?php

namespace App\Filament\Resources\Quotes\Pages;

use App\Filament\Resources\Quotes\QuoteResource;
use App\Services\QuoteTotals;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

/**
 * Edit a quote. Allowed any time UNTIL it's been converted to an invoice —
 * after that the quote is frozen as the historical record of what was offered.
 */
class EditQuote extends EditRecord
{
    protected static string $resource = QuoteResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        if ($this->record->isConverted()) {
            Notification::make()
                ->title('Η προσφορά έχει μετατραπεί σε παραστατικό και δεν επεξεργάζεται.')
                ->warning()
                ->send();

            $this->redirect(QuoteResource::getUrl('view', ['record' => $this->record]));
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    /** Recompute totals after the lines are saved. */
    protected function afterSave(): void
    {
        app(QuoteTotals::class)($this->record);
    }
}
