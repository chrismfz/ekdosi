<?php

namespace Tests\Feature\Cmr;

use App\Filament\Resources\Cmr\Pages\CreateCmr;
use App\Models\CmrLine;
use App\Models\CmrNote;
use App\Models\Company;
use App\Models\Customer;
use App\Models\DeliveryNote;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\Cmr\CmrPdf;
use App\Services\Cmr\CreateCmrFromSource;
use App\Support\TransliterateGreek;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

class CmrFeatureTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private InvoiceType $type;

    private PaymentMethod $pm;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Company::create([
            'name' => 'ΠΡΟΚΟΜΑΞ ΙΚΕ', 'name_en' => 'PROCOMAX P.C.', 'slug' => 'cmr-'.uniqid(),
            'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
            'afm' => '801017172', 'address' => 'ΑΝΑΚΡΕΟΝΤΟΣ 3', 'city' => 'ΠΕΡΙΣΤΕΡΙ', 'postcode' => '12136',
        ]);
        $this->type = InvoiceType::create(['company_id' => $this->tenant->id, 'code' => 'TPY', 'name' => 'ΤΠΥ', 'invcount' => 1, 'mydata_type' => '2.1']);
        $this->pm = PaymentMethod::create(['company_id' => $this->tenant->id, 'description' => 'Μετρητά', 'due_days' => 0]);
    }

    private function invoiceWithLine(): Invoice
    {
        $seq = Invoice::query()->where('company_id', $this->tenant->id)->count() + 1;
        $customer = Customer::create([
            'company_id' => $this->tenant->id, 'name' => 'Πελάτης Σόφια', 'afm' => (string) random_int(100000000, 999999999),
            'address1' => 'Bulgaria blvd 1', 'city' => 'Σόφια', 'postcode' => '1000', 'country' => 'BG',
        ]);
        $inv = Invoice::create([
            'company_id' => $this->tenant->id, 'invcode' => 'TPY'.$seq, 'code' => $seq,
            'invoice_type_id' => $this->type->id, 'payment_method_id' => $this->pm->id, 'customer_id' => $customer->id,
            'issued_at' => now(), 'local_status' => 'active', 'header_discount_percent' => 0,
            'net_total' => 100, 'gross_total' => 124,
            'company_name' => 'Πελάτης Σόφια', 'vat_no' => '999',
            'address1' => 'Bulgaria blvd 1', 'city' => 'Σόφια', 'postcode' => '1000', 'country' => 'BG',
        ]);
        $inv->lines()->create([
            'company_id' => $this->tenant->id, 'product_descr' => 'Εξυπηρετητής DELL', 'qty' => 2,
            'price_per_item' => 50, 'vat_percent' => 24,
        ]);

        return $inv;
    }

    public function test_transliteration_greek_to_latin(): void
    {
        $this->assertSame('Papadopoulos', TransliterateGreek::toLatin('Παπαδόπουλος'));
        $this->assertSame('ACME LTD', TransliterateGreek::toLatin('ACME LTD')); // ASCII left intact
        $this->assertSame('', TransliterateGreek::toLatin(null));
    }

    public function test_create_cmr_from_invoice_prefills_and_transliterates(): void
    {
        $invoice = $this->invoiceWithLine();

        $cmr = app(CreateCmrFromSource::class)->fromInvoice($invoice);

        $this->assertSame(CmrNote::STATUS_DRAFT, $cmr->status);
        $this->assertSame($invoice->id, $cmr->source_id);
        $this->assertSame(Invoice::class, $cmr->source_type);
        $this->assertSame('TPY1', $cmr->reference_no);
        $this->assertSame(1, $cmr->number);
        // Sender = the company's official English name.
        $this->assertStringContainsString('PROCOMAX P.C.', $cmr->sender_text);
        // Consignee transliterated (Σόφια → Sofia-ish, no Greek chars).
        $this->assertDoesNotMatchRegularExpression('/[\x{0370}-\x{03FF}]/u', $cmr->consignee_text);
        // Line carried over + transliterated + packages from qty.
        $this->assertCount(1, $cmr->lines);
        $this->assertSame(2, $cmr->lines->first()->packages_count);
        $this->assertDoesNotMatchRegularExpression('/[\x{0370}-\x{03FF}]/u', $cmr->lines->first()->nature_en);
    }

    public function test_number_increments_per_company(): void
    {
        $a = app(CreateCmrFromSource::class)->fromInvoice($this->invoiceWithLine());
        $b = app(CreateCmrFromSource::class)->fromInvoice($this->invoiceWithLine());

        $this->assertSame(1, $a->number);
        $this->assertSame(2, $b->number);
    }

    public function test_create_cmr_from_delivery_note(): void
    {
        $customer = Customer::create([
            'company_id' => $this->tenant->id, 'name' => 'Παραλήπτης ΑΕ', 'afm' => '888', 'country' => 'BG',
        ]);
        $note = DeliveryNote::create([
            'company_id' => $this->tenant->id, 'invcode' => 'DA1', 'code' => 1,
            'delivery_type_id' => $this->type->id, 'customer_id' => $customer->id, 'issued_at' => now(),
            'local_status' => 'draft', 'recipient_name' => 'Παραλήπτης ΑΕ',
            'loading_street' => 'ΑΝΑΚΡΕΟΝΤΟΣ', 'loading_number' => '3', 'loading_city' => 'ΠΕΡΙΣΤΕΡΙ',
            'delivery_street' => 'Tsarigradsko', 'delivery_city' => 'Σόφια', 'delivery_postcode' => '1000',
            'vehicle_number' => 'ΑΒΓ-1234', 'carrier_afm' => '094000000',
        ]);
        $note->lines()->create([
            'company_id' => $this->tenant->id, 'product_descr' => 'Διακομιστής', 'qty' => 1,
        ]);

        $cmr = app(CreateCmrFromSource::class)->fromDeliveryNote($note);

        $this->assertSame(DeliveryNote::class, $cmr->source_type);
        $this->assertSame('DA1', $cmr->reference_no);
        $this->assertNotEmpty($cmr->taking_over_place);
        $this->assertStringContainsString('ΑΦΜ', (string) $cmr->carrier_name); // carrier from ΔΑ afm fallback
        $this->assertCount(1, $cmr->lines);
    }

    public function test_pdf_renders_bytes(): void
    {
        $cmr = app(CreateCmrFromSource::class)->fromInvoice($this->invoiceWithLine());

        $bytes = app(CmrPdf::class)->render($cmr);

        $this->assertStringStartsWith('%PDF', $bytes);
    }

    public function test_standalone_cmr_gets_number_and_default_reference(): void
    {
        $cmr = CmrNote::create([
            'company_id' => $this->tenant->id,
            'consignee_text' => 'Some third party, Sofia, BG',
        ]);

        $this->assertSame(1, $cmr->number);
        $this->assertSame('CMR-1', $cmr->reference_no);
        $this->assertNotNull($cmr->issued_at);
    }

    public function test_number_counter_is_independent_per_tenant(): void
    {
        $a1 = CmrNote::create(['company_id' => $this->tenant->id, 'consignee_text' => 'A1']);

        $other = Company::create([
            'name' => 'Other OE', 'slug' => 'cmr-o-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
        $b1 = CmrNote::create(['company_id' => $other->id, 'consignee_text' => 'B1']);
        $a2 = CmrNote::create(['company_id' => $this->tenant->id, 'consignee_text' => 'A2']);

        $this->assertSame(1, $a1->number);
        $this->assertSame(1, $b1->number); // independent per company
        $this->assertSame(2, $a2->number);
    }

    public function test_create_form_stamps_company_id_on_lines(): void
    {
        // Exercises the Filament Repeater path — the HasMany create stamps only
        // cmr_note_id, so CmrLine must back-fill company_id (else NOT NULL crash).
        Gate::before(fn () => true);
        $this->actingAs(User::create(['name' => 'Op', 'email' => 'op-'.uniqid().'@t.local', 'password' => bcrypt('x')]));
        Filament::setTenant($this->tenant);

        Livewire::test(CreateCmr::class)
            ->fillForm([
                'reference_no' => 'CMR-TEST',
                'consignee_text' => 'Third party, Sofia, BG',
                'lines' => [
                    ['nature_en' => 'DELL Server', 'packages_count' => 1, 'weight_kg' => 12.5],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $cmr = CmrNote::where('company_id', $this->tenant->id)->where('reference_no', 'CMR-TEST')->firstOrFail();
        $line = CmrLine::where('cmr_note_id', $cmr->id)->firstOrFail();
        $this->assertSame($this->tenant->id, $line->company_id); // back-filled
        $this->assertSame('DELL Server', $line->nature_en);
    }
}
