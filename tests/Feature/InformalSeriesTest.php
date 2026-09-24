<?php

namespace Tests\Feature;

use App\Actions\IssueCreditNote;
use App\Actions\StageServiceRenewal;
use App\Enums\BillingCycle;
use App\Enums\ServiceContractStatus;
use App\Filament\Resources\Invoices\Pages\CreateInvoice;
use App\Filament\Resources\Invoices\Pages\EditInvoice;
use App\Filament\Resources\Invoices\Pages\ViewInvoice;
use App\Filament\Resources\InvoiceTypes\Pages\EditInvoiceType;
use App\Filament\Resources\ServiceContracts\Pages\CreateServiceContract;
use App\Models\Company;
use App\Models\Customer;
use App\Models\DeliveryNote;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\InvoiceType;
use App\Models\MyDataMark;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\ServiceContract;
use App\Models\User;
use App\Services\Accounting\LedgerBook;
use App\Services\CustomerLedger\CustomerLedgerBuilder;
use App\Services\Dashboard\DashboardMetrics;
use App\Services\Dashboard\VatPeriodReport;
use App\Services\EInvoice\GrProviderSubmitter;
use App\Services\EInvoice\ProviderTransportRegistry;
use App\Services\InvoiceBalance;
use App\Services\MyData\MyDataConfigAudit;
use App\Services\MyData\Orphans\OrphanImporter;
use App\Services\MyData\Orphans\OrphanLinker;
use App\Services\MyDataSubmitter;
use App\Services\RecomputeInvoiceTotals;
use App\Services\Reminders\ReminderPlanner;
use App\Support\InvoiceScope;
use App\Support\Pdf\InvoiceBannerState;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * Άτυπη (μη φορολογική) σειρά — docs/non-billable-services.md. A document of an
 * informal series (our own internal services) runs the normal lifecycle — dates,
 * renewals — but is never filed, never counts in any money/VAT total, is never a
 * receivable/reminder/dunning target, never reaches the customer, prints «ΑΤΥΠΟ».
 */
class InformalSeriesTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private Customer $customer;

    private PaymentMethod $credit;

    private InvoiceType $saleType;

    private InvoiceType $informalType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Company::create([
            'name' => 'Informal test', 'slug' => 'informal-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off', 'afm' => '800561849',
        ]);
        $this->customer = Customer::create([
            'company_id' => $this->tenant->id, 'name' => 'Εμείς', 'afm' => '123456789', 'email' => 'us@example.test',
        ]);
        $this->credit = PaymentMethod::create(['company_id' => $this->tenant->id, 'description' => 'Πίστωση', 'due_days' => 30]);
        $this->saleType = InvoiceType::create([
            'company_id' => $this->tenant->id, 'code' => 'ΤΠΥ', 'name' => 'Τιμολόγιο Παροχής', 'invcount' => 1, 'mydata_type' => '2.1',
        ]);
        $this->informalType = InvoiceType::create([
            'company_id' => $this->tenant->id, 'code' => 'ΕΣΩ', 'name' => 'Εσωτερικά', 'invcount' => 1, 'is_informal' => true,
        ]);
    }

    public function test_an_informal_document_counts_in_no_money_or_vat_total(): void
    {
        $fiscal = $this->document($this->saleType, qty: 1); // 124.00 — the only real sale
        $informal = $this->document($this->informalType, qty: 10); // 1240.00 — must vanish everywhere

        $may = [Carbon::parse('2026-05-01'), Carbon::parse('2026-05-31')];
        $metrics = new DashboardMetrics($this->tenant);

        $this->assertEqualsWithDelta(124.0, $metrics->outstandingReceivables(), 0.001);
        $this->assertEqualsWithDelta(124.0, $metrics->income(...$may)->gross, 0.001);
        $this->assertSame(1, $metrics->income(...$may)->count);
        $this->assertEqualsWithDelta(124.0, $metrics->outputForVat(...$may)->gross, 0.001);
        $this->assertEqualsWithDelta(124.0, (new VatPeriodReport($this->tenant))->forPeriod(...$may)->outputGross, 0.001);
        $this->assertEqualsWithDelta(100.0, (new LedgerBook($this->tenant))->forPeriod(...$may)->incomeNet(), 0.001);
        $this->assertEqualsWithDelta(124.0, app(CustomerLedgerBuilder::class)->build($this->customer)->stats['balance'], 0.001);
        $scoped = Customer::query()->where('customers.company_id', $this->tenant->id)
            ->withOutstandingBalance($this->tenant->id)->where('customers.id', $this->customer->id)->first();
        $this->assertEqualsWithDelta(124.0, (float) $scoped->outstanding_balance, 0.001);
        // Never filed by design → never the dashboard's «μη υποβληθέντα» backlog.
        $this->assertSame(1, $metrics->unfiledCount());

        // Never a reminder / overdue / dunning / payment target.
        $this->assertNotContains($informal->id, app(ReminderPlanner::class)->openDocuments($this->tenant)->pluck('invoices.id')->all());
        $this->assertNotContains($informal->id, Invoice::query()->overdue(Carbon::parse('2026-09-01'))->pluck('invoices.id')->all());
        $this->assertTrue($fiscal->fresh()->isOverdue(Carbon::parse('2026-09-01')));
        $this->assertFalse($informal->fresh()->isOverdue(Carbon::parse('2026-09-01')));
        $this->assertNotContains($informal->id, InvoiceScope::customerSettleable(Invoice::query())->pluck('id')->all());
    }

    public function test_service_dates_advance_normally_on_an_informal_renewal(): void
    {
        $contract = ServiceContract::create([
            'company_id' => $this->tenant->id, 'customer_id' => $this->customer->id,
            'invoice_type_id' => $this->informalType->id, 'payment_method_id' => $this->credit->id,
            'description' => 'Hosting (δικό μας)', 'billing_cycle' => BillingCycle::Annual, 'quantity' => 1,
            'amount' => 100, 'vat_percent' => 24, 'status' => ServiceContractStatus::Active,
            'start_date' => '2025-10-01', 'next_due_date' => '2026-10-01',
        ]);

        $draft = app(StageServiceRenewal::class)($contract, Carbon::parse('2026-10-01'));
        $this->assertSame($this->informalType->id, $draft->invoice_type_id);

        $draft->update(['local_status' => 'active']);

        $this->assertSame('2027-10-01', $contract->fresh()->next_due_date->toDateString());
    }

    public function test_finalize_numbers_an_informal_draft_even_on_a_provider_tenant(): void
    {
        $this->tenant->forceFill(['einvoice_provider' => 'gr-provider', 'einvoice_provider_key' => 'invosign', 'einvoice_provider_mode' => 'production'])->save();
        $this->actingAsOperator();
        $informal = $this->document($this->informalType, qty: 1, status: 'draft', numbered: false);
        $fiscal = $this->document($this->saleType, qty: 1, status: 'draft', numbered: false);

        Livewire::test(ViewInvoice::class, ['record' => $informal->getKey()])->callAction('finalize');
        Livewire::test(ViewInvoice::class, ['record' => $fiscal->getKey()])->callAction('finalize');

        // The informal one is issued with its own number; a fiscal one on a provider
        // tenant still waits for the send (unchanged behaviour).
        $this->assertSame(1, (int) $informal->fresh()->code);
        $this->assertSame('ΕΣΩ1', $informal->fresh()->invcode);
        $this->assertNull($fiscal->fresh()->code);
    }

    public function test_neither_submitter_ever_files_an_informal_document(): void
    {
        $this->tenant->forceFill(['mydata_mode' => 'sandbox', 'mydata_aade_id_sandbox' => 'U', 'mydata_subscription_key_sandbox' => 'K'])->save();
        $informal = $this->document($this->informalType, qty: 1, status: 'draft', numbered: false);

        foreach ([
            new MyDataSubmitter($this->tenant->fresh()),
            new GrProviderSubmitter($this->tenant->fresh(), app(ProviderTransportRegistry::class)->for('invosign')),
        ] as $submitter) {
            try {
                $submitter->submit($informal->fresh());
                $this->fail($submitter::class.' filed an informal document.');
            } catch (RuntimeException $e) {
                $this->assertSame(Invoice::INFORMAL_NOT_FILEABLE, $e->getMessage());
            }
        }

        $this->assertNull($informal->fresh()->code, 'No number may be reserved for a refused filing.');
        $this->assertSame(0, MyDataMark::query()->where('invoice_id', $informal->id)->count());
    }

    public function test_an_informal_document_never_reaches_the_customer(): void
    {
        $this->tenant->forceFill(['auto_email_on_issue' => true])->save();
        $informal = $this->document($this->informalType, qty: 1)->fresh();
        $fiscal = $this->document($this->saleType, qty: 1)->fresh();

        $this->assertTrue($fiscal->isPubliclyViewable());
        $this->assertTrue($fiscal->shouldAutoEmailOnFinalize());

        $this->assertFalse($informal->isPubliclyViewable());
        $this->assertFalse($informal->isCustomerVisible());
        $this->assertFalse($informal->isCustomerPayable());
        $this->assertFalse($informal->shouldAutoEmailOnFinalize());
        // Not even as an offered προτιμολόγιο (the action is hidden; the model says no too).
        $offered = $this->document($this->informalType, qty: 1, status: 'draft', numbered: false);
        $offered->forceFill(['offered_at' => now()])->save();
        $this->assertFalse($offered->fresh()->isCustomerVisible());
        $this->assertSame('informal', InvoiceBannerState::for($informal)['kind']);
    }

    public function test_the_pdf_banner_says_informal_even_where_an_issued_document_would_be_pending_mydata(): void
    {
        $this->tenant->forceFill(['einvoice_provider' => 'gr-provider', 'einvoice_provider_key' => 'invosign', 'einvoice_provider_mode' => 'production'])->save();

        $this->assertSame('informal', InvoiceBannerState::for($this->document($this->informalType, qty: 1)->fresh())['kind']);
        $this->assertSame('pending_mydata', InvoiceBannerState::for($this->document($this->saleType, qty: 1)->fresh())['kind']);
    }

    public function test_the_informal_flag_freezes_once_the_series_has_any_document(): void
    {
        $this->document($this->saleType, qty: 1);
        $this->assertFlagLocked($this->saleType, 'A fiscal series with issued documents must not turn informal.');

        // A mere «draft» already counts: it can be filed/numbered or hold a payment.
        $draftOnly = InvoiceType::create(['company_id' => $this->tenant->id, 'code' => 'ΤΙΜ', 'name' => 'Τιμολόγιο', 'invcount' => 1]);
        $this->document($draftOnly, qty: 1, status: 'draft', numbered: false);
        $this->assertFlagLocked($draftOnly, 'A series holding a draft must not turn informal.');

        // A deleted NUMBERED document still locks it (it left a trace in the series)…
        $traced = InvoiceType::create(['company_id' => $this->tenant->id, 'code' => 'ΔΟΚ2', 'name' => 'Δοκιμή', 'invcount' => 1]);
        Invoice::query()->whereKey($this->document($traced, qty: 1)->id)->update(['deleted_at' => now()]);
        $this->assertFlagLocked($traced, 'A deleted numbered document must still lock its series.');

        // Delivery notes are documents of the series too.
        $movement = InvoiceType::create(['company_id' => $this->tenant->id, 'code' => 'ΔΑ', 'name' => 'Δελτίο', 'invcount' => 1]);
        DeliveryNote::create([
            'company_id' => $this->tenant->id, 'invcode' => 'ΔΑ1', 'code' => 1,
            'delivery_type_id' => $movement->id, 'issued_at' => now(), 'local_status' => 'active',
        ]);
        $this->assertFlagLocked($movement, 'A series with delivery notes must not turn informal.');

        // A WHMCS inbox default is locked even before its first document.
        $whmcs = InvoiceType::create(['company_id' => $this->tenant->id, 'code' => 'ΤΠΥW', 'name' => 'WHMCS', 'invcount' => 1]);
        $this->tenant->forceFill(['whmcs_default_invoice_type_id' => $whmcs->id])->save();
        $this->assertFlagLocked($whmcs, 'A WHMCS default series must not turn informal.', 'προεπιλογή του WHMCS');

        // …a deleted, never-numbered draft doesn't lock a series forever.
        $abandoned = InvoiceType::create(['company_id' => $this->tenant->id, 'code' => 'ΔΟΚ1', 'name' => 'Δοκιμή', 'invcount' => 1]);
        $this->document($abandoned, qty: 1, status: 'draft', numbered: false)->delete();
        $abandoned->fresh()->update(['is_informal' => true]);
        $this->assertTrue((bool) $abandoned->fresh()->is_informal);

        // A series without any document may still change…
        $fresh = InvoiceType::create(['company_id' => $this->tenant->id, 'code' => 'ΔΟΚ', 'name' => 'Νέα', 'invcount' => 1]);
        $fresh->update(['is_informal' => true]);
        $this->assertTrue((bool) $fresh->fresh()->is_informal);

        // …but an informal series never carries a myDATA type.
        $this->expectException(RuntimeException::class);
        $fresh->update(['mydata_type' => '2.1']);
    }

    private function assertFlagLocked(InvoiceType $type, string $why, string $reason = 'έχει ήδη παραστατικά'): void
    {
        try {
            $type->fresh()->update(['is_informal' => true]);
            $this->fail($why);
        } catch (RuntimeException $e) {
            $this->assertStringContainsString($reason, $e->getMessage());
        }
        $this->assertFalse((bool) $type->fresh()->is_informal);
    }

    public function test_an_informal_document_is_cancelled_never_credited(): void
    {
        $informal = $this->document($this->informalType, qty: 1);
        $creditType = InvoiceType::create([
            'company_id' => $this->tenant->id, 'code' => 'ΠΙΣ', 'name' => 'Πιστωτικό', 'invcount' => 1, 'is_credit' => true, 'mydata_type' => '5.1',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Άτυπο παραστατικό — ακυρώνεται, δεν πιστώνεται.');

        app(IssueCreditNote::class)($informal->fresh('lines'), $creditType, [['line_id' => $informal->lines->first()->id, 'qty' => 1]]);
    }

    public function test_the_customer_default_series_prefills_new_invoices_and_services(): void
    {
        $this->customer->update(['default_invoice_type_id' => $this->informalType->id]);
        $this->actingAsOperator();

        Livewire::test(CreateInvoice::class)
            ->fillForm(['customer_id' => $this->customer->id])
            ->assertSchemaStateSet(['invoice_type_id' => $this->informalType->id]);

        // Never clobbers a series the operator already picked.
        Livewire::test(CreateInvoice::class)
            ->fillForm(['invoice_type_id' => $this->saleType->id])
            ->fillForm(['customer_id' => $this->customer->id])
            ->assertSchemaStateSet(['invoice_type_id' => $this->saleType->id]);

        Livewire::test(CreateServiceContract::class)
            ->fillForm(['customer_id' => $this->customer->id])
            ->assertSchemaStateSet(['invoice_type_id' => $this->informalType->id]);
    }

    public function test_a_numbered_document_never_crosses_the_informal_line(): void
    {
        // ΕΣΩ5 finalized, then reverted to draft: its number belongs to the informal series.
        $informal = $this->document($this->informalType, qty: 1);
        $informal->update(['local_status' => 'draft']);
        $fiscal = $this->document($this->saleType, qty: 1);

        foreach ([[$informal, $this->saleType], [$fiscal, $this->informalType]] as [$doc, $to]) {
            try {
                $doc->fresh()->update(['invoice_type_id' => $to->id]);
                $this->fail($doc->invcode.' crossed the informal line with its number.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('έχει ήδη αριθμό', $e->getMessage());
            }
            $this->assertNotSame($to->id, $doc->fresh()->invoice_type_id);
        }

        // An UNNUMBERED draft is still free to change series.
        $draft = $this->document($this->saleType, qty: 1, status: 'draft', numbered: false);
        $draft->update(['invoice_type_id' => $this->informalType->id]);
        $this->assertTrue($draft->fresh()->isInformal());

        // The edit form freezes the type of a numbered draft (not only once filed).
        $this->actingAsOperator();
        Livewire::test(EditInvoice::class, ['record' => $informal->getKey()])->assertFormFieldDisabled('invoice_type_id');
        Livewire::test(EditInvoice::class, ['record' => $draft->getKey()])->assertFormFieldEnabled('invoice_type_id');
    }

    public function test_a_deleted_informal_series_still_makes_its_documents_informal(): void
    {
        $informal = $this->document($this->informalType, qty: 1);
        $this->informalType->delete(); // soft delete — the SQL twin still reads it

        $doc = $informal->fresh();
        $this->assertTrue($doc->isInformal());
        $this->assertFalse($doc->isPubliclyViewable());
        $this->assertFalse($doc->isOverdue(Carbon::parse('2026-09-01')));
        $this->assertNotContains($doc->id, InvoiceScope::live(Invoice::query())->pluck('id')->all());
    }

    public function test_a_locked_informal_series_cannot_pick_up_a_mydata_type_or_turn_credit(): void
    {
        $this->document($this->informalType, qty: 1); // the toggle is now locked
        $this->actingAsOperator();

        Livewire::test(EditInvoiceType::class, ['record' => $this->informalType->getKey()])
            ->assertFormFieldDisabled('is_informal')
            ->assertFormFieldDisabled('mydata_type')
            ->assertFormFieldDisabled('is_credit');
        Livewire::test(EditInvoiceType::class, ['record' => $this->saleType->getKey()])
            ->assertFormFieldEnabled('mydata_type')
            ->assertFormFieldEnabled('is_credit');
    }

    public function test_the_customer_default_series_brings_its_own_header_defaults(): void
    {
        $cash = PaymentMethod::create(['company_id' => $this->tenant->id, 'description' => 'Μετρητά', 'due_days' => 0]);
        $this->informalType->update(['payment_method_id' => $cash->id]);
        $tda = InvoiceType::create([
            'company_id' => $this->tenant->id, 'code' => 'ΤΔΑ', 'name' => 'Τιμολόγιο-Δελτίο', 'invcount' => 1,
            'mydata_type' => '1.1', 'is_delivery_note' => true,
        ]);
        $other = Customer::create(['company_id' => $this->tenant->id, 'name' => 'Αποθήκη', 'afm' => '094014201', 'default_invoice_type_id' => $tda->id]);
        $this->customer->update(['default_invoice_type_id' => $this->informalType->id, 'payment_method_id' => $this->credit->id]);
        $this->actingAsOperator();

        // The type's payment method wins over the customer's, as when picked by hand.
        Livewire::test(CreateInvoice::class)
            ->fillForm(['customer_id' => $this->customer->id])
            ->assertSchemaStateSet(['invoice_type_id' => $this->informalType->id, 'payment_method_id' => $cash->id]);
        Livewire::test(CreateInvoice::class)
            ->fillForm(['customer_id' => $other->id])
            ->assertSchemaStateSet(['invoice_type_id' => $tda->id, 'is_delivery_note' => true]);

        // Same from the Καρτέλα's «Νέο Παραστατικό» (?customer_id=N).
        Livewire::withQueryParams(['customer_id' => $this->customer->id])
            ->test(CreateInvoice::class)
            ->assertSchemaStateSet(['invoice_type_id' => $this->informalType->id, 'payment_method_id' => $cash->id]);
    }

    public function test_save_and_submit_on_an_informal_series_saves_a_draft_and_says_why_it_was_not_filed(): void
    {
        $this->tenant->forceFill(['mydata_mode' => 'sandbox', 'mydata_aade_id_sandbox' => 'U', 'mydata_subscription_key_sandbox' => 'K'])->save();
        $this->actingAsOperator();

        Livewire::test(CreateInvoice::class)
            ->fillForm([
                'invoice_type_id' => $this->informalType->id, 'customer_id' => $this->customer->id,
                'payment_method_id' => $this->credit->id,
                'lines' => [['product_descr' => 'Hosting', 'qty' => 1, 'price_per_item' => 100, 'discount' => 0, 'vat_percent' => '24.00']],
            ])
            ->callAction(TestAction::make('saveAndSubmit')->schemaComponent('form-actions', schema: 'content'))
            ->assertHasNoFormErrors()
            ->assertNotified('Αποθηκεύτηκε ως πρόχειρο — άτυπη σειρά');

        $invoice = Invoice::query()->where('company_id', $this->tenant->id)->sole();
        $this->assertSame('draft', $invoice->local_status);
        $this->assertNull($invoice->code);
        $this->assertSame(0, MyDataMark::query()->where('invoice_id', $invoice->id)->count());
    }

    public function test_a_paid_unnumbered_draft_never_crosses_the_informal_line_either(): void
    {
        $draft = $this->document($this->saleType, qty: 1, status: 'draft', numbered: false);
        Payment::create([
            'company_id' => $this->tenant->id, 'customer_id' => $this->customer->id,
            'invoice_id' => $draft->id, 'amount' => 50, 'pay_date' => '2026-05-11',
        ]);

        try {
            $draft->fresh()->update(['invoice_type_id' => $this->informalType->id]);
            $this->fail('A paid draft must not turn informal (its payment would drop out of every balance).');
        } catch (RuntimeException $e) {
            $this->assertSame(Invoice::INFORMAL_LINE_LOCKED, $e->getMessage());
        }

        // The edit form says so as a validation error, not a crash.
        $this->actingAsOperator();
        Livewire::test(EditInvoice::class, ['record' => $draft->getKey()])
            ->fillForm(['invoice_type_id' => $this->informalType->id])
            ->call('save')
            ->assertHasFormErrors(['invoice_type_id']);
        $this->assertSame($this->saleType->id, $draft->fresh()->invoice_type_id);

        // Fiscal → fiscal stays free for an unnumbered draft.
        $other = InvoiceType::create(['company_id' => $this->tenant->id, 'code' => 'ΤΙΜ', 'name' => 'Τιμολόγιο', 'invcount' => 1, 'mydata_type' => '1.1']);
        $draft->fresh()->update(['invoice_type_id' => $other->id]);
        $this->assertSame($other->id, $draft->fresh()->invoice_type_id);
    }

    public function test_turning_an_unused_series_informal_in_the_form_saves_it_cleanly(): void
    {
        $this->actingAsOperator();

        // ΤΠΥ has a myDATA type and no documents: the toggle clears it, and the
        // (now disabled) field's reset must still be saved.
        Livewire::test(EditInvoiceType::class, ['record' => $this->saleType->getKey()])
            ->fillForm(['is_informal' => true])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertTrue((bool) $this->saleType->fresh()->is_informal);
        $this->assertNull($this->saleType->fresh()->mydata_type);

        // A seeded ΤΔΑ series (no is_delivery_note field in the form) is refused
        // as a validation error, not a crash.
        $tda = InvoiceType::create([
            'company_id' => $this->tenant->id, 'code' => 'ΤΔΑ', 'name' => 'Τιμολόγιο-Δελτίο', 'invcount' => 1,
            'mydata_type' => '1.1', 'is_delivery_note' => true,
        ]);
        Livewire::test(EditInvoiceType::class, ['record' => $tda->getKey()])
            ->fillForm(['is_informal' => true])
            ->call('save')
            ->assertHasFormErrors(['is_informal']);
        $this->assertFalse((bool) $tda->fresh()->is_informal);
    }

    public function test_a_deleted_default_series_is_not_prefilled(): void
    {
        $this->customer->update(['default_invoice_type_id' => $this->informalType->id]);
        $this->informalType->delete();
        $this->actingAsOperator();

        Livewire::test(CreateInvoice::class)
            ->fillForm(['customer_id' => $this->customer->id])
            ->assertSchemaStateSet(['invoice_type_id' => null]);
        Livewire::test(CreateServiceContract::class)
            ->fillForm(['customer_id' => $this->customer->id])
            ->assertSchemaStateSet(['invoice_type_id' => null]);
    }

    public function test_a_filed_document_never_lands_in_an_informal_series(): void
    {
        // Orphan import: a myDATA document filed under «ΕΣΩ» is never imported into it…
        $doc = ['mark' => '400001234567890', 'invoiceType' => '2.1', 'series' => 'ΕΣΩ', 'aa' => '1', 'issuerVat' => $this->tenant->afm, 'state' => 'VALID'];
        $this->assertCount(0, OrphanImporter::eligibleTypes($this->tenant, $doc));

        // …nor linked to a local informal document of the same series/ΑΑ…
        $informal = $this->document($this->informalType, qty: 1);
        $informal->forceFill(['code' => 1, 'invcode' => 'ΕΣΩ1', 'series' => 'ΕΣΩ'])->save();
        $this->assertStringContainsString('άτυπο', (string) app(OrphanLinker::class)->blocker($this->tenant, $informal->fresh(), $doc));

        // …and the model refuses an AADE identity on an informal document on any path.
        try {
            $informal->fresh()->forceFill(['mydata_mark' => '400001234567890', 'mydata_state' => 'VALID'])->save();
            $this->fail('An informal document must never carry a MARK.');
        } catch (RuntimeException $e) {
            $this->assertSame(Invoice::INFORMAL_NEVER_FILED, $e->getMessage());
        }
        $this->assertNull($informal->fresh()->mydata_mark);
    }

    public function test_a_credit_note_draft_never_moves_to_an_informal_series(): void
    {
        $original = $this->document($this->saleType, qty: 1);
        $creditType = InvoiceType::create([
            'company_id' => $this->tenant->id, 'code' => 'ΠΙΣ', 'name' => 'Πιστωτικό', 'invcount' => 1, 'is_credit' => true, 'mydata_type' => '5.1',
        ]);
        $credit = $this->document($creditType, qty: 1, status: 'draft', numbered: false);
        $credit->forceFill(['credited_invoice_id' => $original->id])->save();

        try {
            $credit->fresh()->update(['invoice_type_id' => $this->informalType->id]);
            $this->fail('A credit-note draft must not move to an informal series.');
        } catch (RuntimeException $e) {
            $this->assertSame(Invoice::INFORMAL_NOT_CREDIT, $e->getMessage());
        }

        $this->actingAsOperator();
        Livewire::test(EditInvoice::class, ['record' => $credit->getKey()])
            ->fillForm(['invoice_type_id' => $this->informalType->id])
            ->call('save')
            ->assertHasFormErrors(['invoice_type_id']);
        $this->assertSame($creditType->id, $credit->fresh()->invoice_type_id);
    }

    public function test_a_default_series_reconfigured_as_credit_is_not_prefilled(): void
    {
        $this->saleType->update(['is_credit' => true]); // was a sale type when picked
        $this->customer->update(['default_invoice_type_id' => $this->saleType->id]);
        $this->actingAsOperator();

        Livewire::test(CreateInvoice::class)
            ->fillForm(['customer_id' => $this->customer->id])
            ->assertSchemaStateSet(['invoice_type_id' => null]);
        Livewire::test(CreateServiceContract::class)
            ->fillForm(['customer_id' => $this->customer->id])
            ->assertSchemaStateSet(['invoice_type_id' => null]);
    }

    public function test_the_config_audit_does_not_flag_an_informal_series_as_missing_its_mydata_type(): void
    {
        $row = app(MyDataConfigAudit::class)->auditInvoiceType($this->informalType->fresh());

        $this->assertSame('ok', $row->status());
    }

    private function document(InvoiceType $type, int $qty, string $status = 'active', bool $numbered = true): Invoice
    {
        $invoice = Invoice::create(array_filter([
            'company_id' => $this->tenant->id, 'invoice_type_id' => $type->id, 'customer_id' => $this->customer->id,
            'payment_method_id' => $this->credit->id, 'issued_at' => '2026-05-10 10:00:00', 'local_status' => $status,
            'invcode' => $numbered ? $type->code.uniqid() : null, 'code' => $numbered ? 1 : null,
        ], fn ($v) => $v !== null));
        InvoiceLine::create([
            'company_id' => $this->tenant->id, 'invoice_id' => $invoice->id,
            'qty' => $qty, 'price_per_item' => 100, 'vat_percent' => 24, 'product_descr' => 'Υπηρεσία',
        ]);

        $invoice = app(RecomputeInvoiceTotals::class)($invoice);
        app(InvoiceBalance::class)->recompute($invoice->fresh()); // payment_status, like a real issue

        return $invoice->fresh();
    }

    private function actingAsOperator(): void
    {
        Gate::before(fn () => true);
        $this->actingAs(User::create(['name' => 'Op', 'email' => 'op-'.uniqid().'@example.test', 'password' => bcrypt('x')]));
        Filament::setTenant($this->tenant);
    }
}
