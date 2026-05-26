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

            return;
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
     * Defense-in-depth re-check against TOCTOU: between mount() (which
     * loads the page with mydata_state=null) and the user clicking
     * Save, another browser tab / a queue job could have filed this
     * invoice with AADE. Saving would then overwrite header fields on
     * a legally-frozen invoice. Refresh + re-assert here, inside the
     * save transaction.
     */
    protected function beforeSave(): void
    {
        $current = $this->record->fresh();
        if ($current?->mydata_state !== null) {
            Notification::make()
                ->title('Invoice was filed in another tab')
                ->body("Invoice {$current->invcode} now has AADE state={$current->mydata_state}. Your edits cannot be saved — refresh the page to see the filed version.")
                ->danger()
                ->persistent()
                ->send();

            $this->halt();
        }
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
