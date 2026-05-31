<?php

namespace App\Filament\Resources\Invoices\Pages;

use App\Filament\Resources\Invoices\InvoiceResource;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Services\EInvoiceSubmitterFactory;
use App\Services\InvoiceNumberer;
use App\Services\RecomputeInvoiceTotals;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Issue a new invoice. Two save paths:
 *
 *   - "Save (draft)" — persists with mydata_state=null. Operator
 *     can review the PDF preview before deciding to submit. ΑΑ is
 *     allocated under a row lock (InvoiceNumberer) inside the same
 *     transaction as the INSERT — same atomic semantics as the
 *     legacy INVOICE_BI1 + INVOICE_AI triggers.
 *
 *   - "Save and Submit to myDATA" — does Save, then immediately
 *     submits via MyDataSubmitter. If submission fails, the invoice
 *     stays as a draft (with the ΑΑ already burned — gap in
 *     sequence acceptable, matches legacy behaviour). Operator can
 *     fix and click "Submit to myDATA" from the view page.
 *
 * The Save-and-Submit button is hidden when the tenant's mode is Off
 * — the equivalent would route to NullSubmitter and confuse
 * operators about whether anything happened.
 */
class CreateInvoice extends CreateRecord
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

    /**
     * Set by the Save-and-Submit button's action() before triggering
     * create(). afterCreate() reads it to know whether to chain a
     * submission. Cleaner than two separate code paths because all
     * the ΑΑ allocation + lines persistence runs once.
     */
    protected bool $shouldSubmitAfterCreate = false;

    protected function handleRecordCreation(array $data): Model
    {
        $tenant = Filament::getTenant();
        if (! $tenant) {
            throw new \RuntimeException('Cannot create invoice without a tenant context.');
        }

        $type = InvoiceType::query()
            ->where('company_id', $tenant->getKey())
            ->whereKey($data['invoice_type_id'])
            ->firstOrFail();

        return DB::transaction(function () use ($data, $tenant, $type) {
            $allocation = app(InvoiceNumberer::class)->allocate($tenant, $type->code);

            $data['code'] = $allocation->code;
            $data['invcode'] = $allocation->invcode;
            $data['company_id'] = $tenant->getKey();

            return Invoice::create($data);
        });
    }

    /**
     * Recompute net_total / gross_total from the persisted lines.
     * Filament's Repeater creates lines AFTER handleRecordCreation,
     * so we can't compute totals there — afterCreate runs once the
     * line rows exist.
     *
     * NOTE on transaction ordering: Filament's CreateRecord wraps the
     * entire create flow — handleRecordCreation, lines persistence,
     * AND this afterCreate hook — inside a single DB transaction
     * (beginDatabaseTransaction → afterCreate → commitDatabaseTransaction
     * in vendor/filament/.../CreateRecord.php). The myDATA HTTP call
     * MUST run outside that transaction: an HTTP round-trip while
     * holding open MariaDB row locks blocks every other writer for
     * seconds, and if the AADE call succeeds but the outer transaction
     * later rolls back (e.g. a totals recompute failure), we have a
     * filed MARK with no local invoice — exactly the orphan-MARK case
     * the legacy MARK_AI0 trigger replacement was designed to prevent.
     *
     * Solution: queue the submission via DB::afterCommit(). The
     * callback fires only after the outermost transaction commits,
     * guaranteeing the invoice + lines are durable before we talk to
     * AADE.
     */
    protected function afterCreate(): void
    {
        $invoice = app(RecomputeInvoiceTotals::class)($this->record);

        if ($this->shouldSubmitAfterCreate) {
            $invoiceId = $invoice->getKey();
            DB::afterCommit(function () use ($invoiceId): void {
                $fresh = Invoice::query()->whereKey($invoiceId)->with('lines')->first();
                if ($fresh) {
                    $this->chainSubmit($fresh);
                }
            });
        }
    }

    private function chainSubmit(Invoice $invoice): void
    {
        try {
            $tenant = Filament::getTenant();
            $submitter = app(EInvoiceSubmitterFactory::class)->for($tenant);
            $mark = $submitter->submit($invoice);

            Notification::make()
                ->title('Filed at myDATA')
                ->body('MARK: '.($mark->mark ?? 'pending').' — see the audit history on the view page.')
                ->success()
                ->send();
        } catch (Throwable $e) {
            Notification::make()
                ->title('Saved as draft, but myDATA submission FAILED')
                ->body($e->getMessage().' Edit the invoice and retry "Submit to myDATA" from the view page.')
                ->danger()
                ->persistent()
                ->send();
        }
    }

    protected function getFormActions(): array
    {
        return [
            $this->getCreateFormAction()->label('Save (draft)'),

            Action::make('saveAndSubmit')
                ->label('Save and Submit to myDATA')
                ->color('success')
                ->icon('heroicon-o-paper-airplane')
                ->visible(fn () => in_array(
                    Filament::getTenant()?->mydata_mode,
                    ['sandbox', 'production'],
                    true,
                ))
                ->requiresConfirmation()
                ->modalHeading('Save AND file with AADE myDATA')
                ->modalDescription(fn () => Filament::getTenant()?->mydata_mode === 'production'
                    ? '⚠ Production mode — this will file a REAL invoice with AADE. The MARK returned is legally binding. Once filed, the invoice cannot be edited; only cancelled + reissued.'
                    : 'Sandbox mode — files against AADE\'s test endpoint. Synthetic MARK, not a real tax record.')
                ->modalSubmitActionLabel('Confirm submission')
                ->action(function () {
                    // Set the chain-submit flag BEFORE triggering the
                    // standard create() flow. afterCreate() picks it up
                    // and submits to AADE once the invoice + lines are
                    // persisted and totals recomputed.
                    $this->shouldSubmitAfterCreate = true;
                    $this->create();
                }),

            $this->getCancelFormAction(),
        ];
    }
}
