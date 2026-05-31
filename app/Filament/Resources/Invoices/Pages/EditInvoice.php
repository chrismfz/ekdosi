<?php

namespace App\Filament\Resources\Invoices\Pages;

use App\Filament\Resources\Invoices\InvoiceResource;
use App\Models\Invoice;
use App\Services\RecomputeInvoiceTotals;
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

    /**
     * Full-width content so the Excel-style lines table uses the whole screen
     * (the default centred container squeezed the columns).
     */
    public function getMaxContentWidth(): \Filament\Support\Enums\Width
    {
        return \Filament\Support\Enums\Width::Full;
    }

    public function mount(int|string $record): void
    {
        parent::mount($record);

        // canAccess() can't see the record easily on resource-level
        // (it runs before the record is resolved). Belt-and-suspenders
        // here: only DRAFTS are editable. Finalised (Ενεργό), filed, or
        // cancelled invoices are locked — revert to draft (if unfiled) or
        // cancel + reissue (if filed).
        if ($this->record->mydata_state !== null || $this->record->local_status !== 'draft') {
            Notification::make()
                ->title('Δεν επιτρέπεται η επεξεργασία')
                ->body("Το παραστατικό {$this->record->invcode} δεν είναι πρόχειρο (κατάσταση: {$this->record->local_status}, myDATA: ".($this->record->mydata_state ?? '—').'). Επαναφέρετέ το σε πρόχειρο ή, αν έχει υποβληθεί, ακυρώστε + επανεκδώστε.')
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
                ->visible(fn (Invoice $record) => $record->mydata_state === null && $record->local_status === 'draft'),
        ];
    }

    /**
     * Defense-in-depth re-check against TOCTOU: between mount() (which
     * loads the page with mydata_state=null) and the user clicking
     * Save, another browser tab / a queue job could have filed this
     * invoice with AADE. Saving would then overwrite header fields on
     * a legally-frozen invoice.
     *
     * Uses lockForUpdate() so the row lock is held by Filament's save
     * transaction until the form UPDATE commits — closing the race
     * window between this SELECT and the subsequent UPDATE. A bare
     * fresh() narrows the window but doesn't close it: a concurrent
     * MyDataSubmitter could still slip a filing in between the read
     * and the write. The lock blocks any concurrent writer (or other
     * SELECT FOR UPDATE) until this save commits or rolls back.
     */
    protected function beforeSave(): void
    {
        $current = Invoice::query()
            ->whereKey($this->record->getKey())
            ->lockForUpdate()
            ->first();

        if ($current?->mydata_state !== null || $current?->local_status !== 'draft') {
            Notification::make()
                ->title('Το παραστατικό άλλαξε σε άλλη καρτέλα')
                ->body("Το {$current?->invcode} δεν είναι πλέον πρόχειρο (κατάσταση: {$current?->local_status}, myDATA: ".($current?->mydata_state ?? '—').'). Οι αλλαγές δεν αποθηκεύονται — ανανεώστε τη σελίδα.')
                ->danger()
                ->persistent()
                ->send();

            $this->halt();
        }
    }

    /**
     * Recompute totals after the form save. Line repeater changes
     * happen during save(); afterSave() runs once the line rows have
     * been persisted, so the lines() relation now sees the new state.
     * Shared with CreateInvoice via RecomputeInvoiceTotals — single
     * source of truth for the formula.
     */
    protected function afterSave(): void
    {
        app(RecomputeInvoiceTotals::class)($this->record);
    }
}
