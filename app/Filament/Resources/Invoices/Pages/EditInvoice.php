<?php

namespace App\Filament\Resources\Invoices\Pages;

use App\Filament\Resources\Invoices\InvoiceResource;
use App\Models\Invoice;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Edit an invoice. Restricted to DRAFTS — once mydata_state is set
 * (VALID or CANCELLED), the invoice is legally frozen and this page
 * refuses to load.
 *
 * Filament's standard navigation generates an Edit link from the
 * resource's record-actions list; the table column already gates that
 * visibility. canAccess() is the defense-in-depth: even a hand-typed
 * URL like /admin/{tenant}/invoices/{id}/edit on a VALID invoice
 * gets bounced.
 */
class EditInvoice extends EditRecord
{
    protected static string $resource = InvoiceResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        // canAccess() can't see the record easily on resource-level
        // (it runs before the record is resolved). Belt-and-suspenders
        // here: refuse to mount if the invoice is filed.
        if ($this->record->mydata_state !== null) {
            Notification::make()
                ->title('Cannot edit a filed invoice')
                ->body("Invoice {$this->record->invcode} has been filed at myDATA (state={$this->record->mydata_state}). Edits would diverge from what AADE recorded. Use Cancel + reissue to correct.")
                ->danger()
                ->persistent()
                ->send();

            $this->redirect(InvoiceResource::getUrl('view', ['record' => $this->record, 'tenant' => $this->record->company]));
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->visible(fn (Invoice $record) => $record->mydata_state === null),
        ];
    }

    /**
     * Recompute totals after the form save, same as CreateInvoice.
     * Line repeater changes happen during save(); afterSave() runs
     * after the lines have been persisted.
     */
    protected function afterSave(): void
    {
        $invoice = $this->record->fresh(['lines']);
        $rawNet = $invoice->lines->sum(fn ($l) => (float) $l->net_price);
        $rawGross = $invoice->lines->sum(fn ($l) => (float) $l->gross_price);
        $discount = 1 - ((float) $invoice->header_discount_percent / 100);

        $invoice->net_total = round($rawNet * $discount, 2);
        $invoice->gross_total = round($rawGross * $discount, 2);
        $invoice->save();
    }
}
