<?php

namespace App\Filament\Resources\Invoices\Pages;

use App\Filament\Resources\Invoices\InvoiceResource;
use App\Models\Invoice;
use App\Services\RecomputeInvoiceTotals;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Enums\Width;

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
    public function getMaxContentWidth(): Width
    {
        return Width::Full;
    }

    public function mount(int|string $record): void
    {
        parent::mount($record);

        // canAccess() can't see the record easily on resource-level
        // (it runs before the record is resolved). Belt-and-suspenders
        // here: only DRAFTS are editable. Finalised (Ενεργό), filed, or
        // cancelled invoices are locked — revert to draft (if unfiled) or
        // cancel + reissue (if filed).
        // An OFFERED draft (προτιμολόγιο) is locked too: the customer is looking at
        // it and may already have paid against it, so its figures must not move
        // under them. «Ανάκληση προσφοράς» reopens it for editing.
        if ($this->record->isOffered()) {
            Notification::make()->warning()
                ->title('Το παραστατικό έχει προσφερθεί στον πελάτη')
                ->body("Το {$this->record->invcode} είναι προτιμολόγιο και δεν επεξεργάζεται. Κάντε «Ανάκληση προσφοράς» πρώτα.")
                ->persistent()->send();

            $this->redirect(InvoiceResource::getUrl('view', ['record' => $this->record]));

            return;
        }

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
            // Gapless-at-send (reverses MON-4): a draft has NO ΑΑ — it carries only a
            // provisional identity («ΠΡΟΣ-…»); the real number is allocated at
            // transmission. So deleting a draft leaves NO gap in the per-series
            // sequence — it never consumed a number. The confirmation only guards
            // against an accidental delete.
            DeleteAction::make()
                // …and not while it holds money: deleting would leave the Payment rows
                // pointing at a soft-deleted invoice, still reducing the customer's
                // balance with no document to explain them.
                ->visible(fn (Invoice $record) => $record->mydata_state === null
                    && $record->local_status === 'draft'
                    && ! $record->hasRecordedPayments())
                ->requiresConfirmation()
                ->modalHeading('Διαγραφή πρόχειρου παραστατικού')
                ->modalDescription(fn (Invoice $record) => "Το πρόχειρο {$record->invcode} θα διαγραφεί. "
                    .'Δεν έχει δεσμεύσει αύξοντα αριθμό (ο ΑΑ μπαίνει στην αποστολή), οπότε ΔΕΝ μένει κενό στη σειρά. Συνέχεια;'),
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
    /**
     * Combined ΤΔΑ (3d-a review P2): toggling «Είναι και Δελτίο Αποστολής» OFF on a
     * draft hides the movement sub-form → Filament stops dehydrating those fields →
     * their stale DB values would survive on a now-plain 1.1 (never FILED — the payload
     * builder gates the movement header on `is_delivery_note` — but latent orphan data
     * on a legal row, and it wouldn't show in the activity-log diff). Clear them
     * explicitly so a plain invoice carries no movement header. Create is already clean
     * (hidden fields never dehydrate → null), so this is the edit-only leak.
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (empty($data['is_delivery_note'])) {
            foreach (Invoice::MOVEMENT_DATA_COLUMNS as $col) {
                $data[$col] = null;
            }
            // NOT-NULL booleans → reset to their default, not null.
            $data['without_digital_transport_tracking'] = false;
            $data['non_obligated_recipient'] = false;
        }

        return $data;
    }

    protected function beforeSave(): void
    {
        $current = Invoice::query()
            ->whereKey($this->record->getKey())
            ->lockForUpdate()
            ->first();

        if ($current?->isOffered()) {
            Notification::make()->warning()
                ->title('Το παραστατικό προσφέρθηκε στον πελάτη')
                ->body("Το {$current?->invcode} έγινε προτιμολόγιο ενώ το επεξεργαζόσασταν. Οι αλλαγές δεν αποθηκεύονται.")
                ->persistent()->send();

            $this->halt();
        }

        // A draft that holds money may not be reassigned to another customer: the
        // Payment rows keep customer A while the document would become B's, so A's
        // Καρτέλα would show a payment against B's document. Impossible before the
        // προτιμολόγιο (drafts could not carry payments), so the draft edit path
        // never had to defend against it.
        if ($current !== null
            && (int) ($this->data['customer_id'] ?? 0) !== (int) $current->customer_id
            && $current->hasRecordedPayments()) {
            Notification::make()->danger()
                ->title('Το παραστατικό κρατά εισπράξεις')
                ->body("Το {$current->invcode} έχει καταχωρισμένες εισπράξεις, οπότε δεν μπορεί να αλλάξει πελάτη. Αφαίρεσε πρώτα τις εισπράξεις.")
                ->persistent()->send();

            $this->halt();
        }

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
