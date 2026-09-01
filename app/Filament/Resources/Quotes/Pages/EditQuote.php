<?php

namespace App\Filament\Resources\Quotes\Pages;

use App\Filament\Resources\Quotes\QuoteResource;
use App\Services\QuoteTotals;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Enums\Width;

/**
 * Edit a quote. Allowed any time UNTIL it's been converted to an invoice —
 * after that the quote is frozen as the historical record of what was offered.
 */
class EditQuote extends EditRecord
{
    protected static string $resource = QuoteResource::class;

    /**
     * Full-width content so the Excel-style lines table uses the whole screen
     * (the default centred container squeezed the columns).
     */
    public function getMaxContentWidth(): Width
    {
        return Width::Full;
    }

    public function mount(int|string $record): void
    {
        parent::mount($record);

        if ($this->record->isConverted()) {
            Notification::make()
                ->title('Η προσφορά έχει μετατραπεί σε παραστατικό και δεν επεξεργάζεται.')
                ->warning()
                ->send();

            $this->redirect(QuoteResource::getUrl('view', ['record' => $this->record, 'tenant' => $this->record->company]));
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    /**
     * Same guard as CreateQuote: the Hidden `lead_id` must belong to this tenant.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (array_key_exists('lead_id', $data)) {
            $data['lead_id'] = CreateQuote::tenantLeadId($data['lead_id']);
        }

        return $data;
    }

    /** Recompute totals after the lines are saved. */
    protected function afterSave(): void
    {
        app(QuoteTotals::class)($this->record);
    }
}
