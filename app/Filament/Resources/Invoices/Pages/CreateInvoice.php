<?php

namespace App\Filament\Resources\Invoices\Pages;

use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\Invoices\Schemas\InvoiceForm;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Services\EInvoiceSubmitterFactory;
use App\Services\RecomputeInvoiceTotals;
use App\Support\CustomerLanguage;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Enums\Width;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Issue a new invoice. Two save paths:
 *
 *   - "Save (draft)" — persists with mydata_state=null and a PROVISIONAL
 *     identity (gapless-at-send): no ΑΑ is consumed, and the Invoice
 *     `created` hook sets invcode = «ΠΡΟΣ-ΤΠΥ-{id}». Operator can review
 *     the PDF preview before deciding to submit.
 *
 *   - "Save and Submit to myDATA" — does Save, then immediately
 *     submits via MyDataSubmitter, which allocates the real ΑΑ before it
 *     transmits (InvoiceNumberer::assign). If submission fails, the invoice
 *     stays a provisional draft (a definitive rejection releases the number;
 *     an ambiguous one keeps it) — no gap in the sequence either way.
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
    public function getMaxContentWidth(): Width
    {
        return Width::Full;
    }

    /**
     * Set by the Save-and-Submit button's action() before triggering
     * create(). afterCreate() reads it to know whether to chain a
     * submission. Cleaner than two separate code paths because all
     * the ΑΑ allocation + lines persistence runs once.
     */
    protected bool $shouldSubmitAfterCreate = false;

    /**
     * Pre-select a customer (and snapshot its details) when arriving from
     * the Καρτέλα's «Νέο Παραστατικό» button (?customer_id=N). This is the
     * "reverse" flow the operator asked for — start an invoice straight
     * from a customer's account. Mirrors the customer_id afterStateUpdated
     * snapshot fill, since a programmatic fill doesn't trigger it. Scoped
     * to the tenant; a foreign / unknown id is silently ignored.
     */
    protected function fillForm(): void
    {
        parent::fillForm();

        $customerId = (int) request()->query('customer_id');
        if ($customerId <= 0) {
            return;
        }

        $customer = Customer::query()
            ->where('company_id', Filament::getTenant()?->getKey())
            ->find($customerId);
        if (! $customer) {
            return;
        }

        // The customer's default series (e.g. our own company → the informal «ΕΣΩ»)
        // plus that type's header defaults — mirrors the customer-select handler,
        // where the type's payment method wins over the customer's.
        $type = $customer->default_invoice_type_id && blank($this->data['invoice_type_id'] ?? null)
            ? InvoiceType::query()->where('company_id', $customer->company_id)->find($customer->default_invoice_type_id)
            : null;
        $typeFields = $type ? ['invoice_type_id' => $type->id] + InvoiceForm::invoiceTypeDefaults($type) : [];

        $this->form->fill(array_merge($this->data ?? [], [
            'customer_id' => $customer->id,
            'company_name' => $customer->name,
            'vat_no' => $customer->afm,
            'vies_vat' => $customer->vat_vies,
            'occupation' => $customer->occupation,
            'address1' => $customer->address1,
            'address2' => $customer->address2,
            'city' => $customer->city,
            'postcode' => $customer->postcode,
            'country' => $customer->country ?: 'GR',
            // Per-customer commercial defaults (mirror the form's customer-select
            // handler). No invoice type chosen yet here, so the customer's payment
            // method is the starting value — the type overrides it once picked.
            'header_discount_percent' => (float) ($customer->discount ?? 0),
            'payment_method_id' => $customer->payment_method_id,
        ], $typeFields));
    }

    protected function handleRecordCreation(array $data): Model
    {
        $tenant = Filament::getTenant();
        if (! $tenant) {
            throw new \RuntimeException('Cannot create invoice without a tenant context.');
        }

        // Validate the chosen type belongs to this tenant before persisting.
        InvoiceType::query()
            ->where('company_id', $tenant->getKey())
            ->whereKey($data['invoice_type_id'])
            ->firstOrFail();

        // Gapless-at-send (reverses MON-4): a draft no longer consumes an ΑΑ. `code`
        // stays null and the Invoice `created` hook assigns a provisional invcode
        // («ΠΡΟΣ-ΤΠΥ-{id}»); the real ΑΑ/invcode/series are allocated at transmission
        // (InvoiceNumberer::assign, called by the submitters).
        unset($data['code'], $data['invcode']);
        $data['company_id'] = $tenant->getKey();

        // i18n stamp-at-issue: an explicit «Γλώσσα PDF» choice wins; on auto, freeze the
        // customer's explicit preference (else null → PdfLabels resolves from the frozen
        // country). Tenant-scoped customer lookup; the decision itself is in the resolver.
        $customer = ! empty($data['customer_id'])
            ? Customer::query()->where('company_id', $tenant->getKey())->whereKey($data['customer_id'])->first()
            : null;
        $data['language'] = CustomerLanguage::stampForDocument($data['language'] ?? null, $customer);

        return Invoice::create($data);
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
        // An informal series is never filed: say so plainly instead of letting the
        // submitter's refusal read as a failure to «retry».
        if ($invoice->isInformal()) {
            Notification::make()
                ->title('Αποθηκεύτηκε ως πρόχειρο — άτυπη σειρά')
                ->body('Τα άτυπα δεν διαβιβάζονται στο myDATA. Οριστικοποίησέ το από τη σελίδα του για να πάρει αριθμό.')
                ->info()
                ->send();

            return;
        }

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
