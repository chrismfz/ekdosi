<?php

namespace Tests\Feature\MyData;

use App\Filament\Resources\Invoices\Pages\CreateInvoice;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\InvoiceType;
use App\Models\MyDataMark;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Models\VatCategory;
use App\Services\MyDataSubmitter;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * A 0% line's §8.3 reason that can never be right for the invoice type (16 —
 * domestic reverse charge — on a foreign-counterpart type; 14 — intra-EU goods —
 * outside 1.2) is refused before filing, and flagged in the invoice form. AADE
 * itself accepts such a document, so without this the wrong legal reason files
 * silently (the MYD-007 defect).
 */
class VatExemptionTypeConflictTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Company::create([
            'name' => 'Conflict test', 'slug' => 'vat-conflict-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'sandbox', 'afm' => '800561849',
            'mydata_aade_id_sandbox' => 'TESTUSER', 'mydata_subscription_key_sandbox' => 'TESTKEY',
        ]);
        // An EU business customer with a full address — a valid 2.2 counterpart.
        $this->customer = Customer::create([
            'company_id' => $this->tenant->id, 'name' => 'Kunde GmbH', 'afm' => '123456789',
            'country' => 'DE', 'address1' => 'Hauptstrasse 1', 'city' => 'Berlin', 'postcode' => '10115',
        ]);
    }

    public function test_domestic_reverse_charge_reason_on_an_intra_eu_service_is_refused(): void
    {
        $invoice = $this->invoiceOfType('2.2', exemption: 16);

        try {
            (new MyDataSubmitter($this->tenant))->previewXml($invoice);
            $this->fail('A 16 on a 2.2 must not file.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('γραμμή 1', $e->getMessage());
            $this->assertStringContainsString('Αιτία 16 σε τύπο 2.2', $e->getMessage());
            // Names the right reason for the type, and points at the LINE.
            $this->assertStringContainsString('η αιτία είναι 4', $e->getMessage());
            $this->assertStringContainsString('αιτία απαλλαγής της γραμμής', $e->getMessage());
            // A draft is editable as is.
            $this->assertStringNotContainsString('Επαναφορά σε πρόχειρο', $e->getMessage());
        }
    }

    public function test_an_issued_invoice_is_told_to_go_back_to_draft_first(): void
    {
        $invoice = $this->invoiceOfType('2.2', exemption: 16);
        $invoice->forceFill(['local_status' => 'active'])->save();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('«Επαναφορά σε πρόχειρο»');

        (new MyDataSubmitter($this->tenant))->previewXml($invoice->fresh('lines'));
    }

    public function test_the_correct_reason_on_the_same_invoice_files(): void
    {
        $xml = (new MyDataSubmitter($this->tenant))->previewXml($this->invoiceOfType('2.2', exemption: 4))->request;

        $this->assertStringContainsString('<vatExemptionCategory>4</vatExemptionCategory>', $xml);
    }

    public function test_a_tenant_wide_default_that_contradicts_the_type_is_refused_and_says_where_it_came_from(): void
    {
        // The line has no reason of its own (a WHMCS / imported line) → the tenant's
        // single 0% category supplies it. Its 16 is just as wrong on a 2.2.
        VatCategory::create([
            'company_id' => $this->tenant->id, 'description' => '0% ενδοκοιν.', 'rate' => 0,
            'vat_exemption_category' => 16, 'is_default' => false,
        ]);
        $invoice = $this->invoiceOfType('2.2', exemption: null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Αιτία 16 σε τύπο 2\.2.*κατηγορίας ΦΠΑ 0% της εταιρείας/u');

        (new MyDataSubmitter($this->tenant))->previewXml($invoice);
    }

    public function test_intra_eu_goods_reason_on_a_domestic_invoice_is_refused(): void
    {
        $this->customer->forceFill(['country' => 'GR'])->save();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Αιτία 14 σε τύπο 1.1');

        (new MyDataSubmitter($this->tenant))->previewXml($this->invoiceOfType('1.1', exemption: 14));
    }

    public function test_a_warn_only_pair_still_files(): void
    {
        // 14 on a 2.2 is suspicious (goods reason on a services invoice) but a mixed
        // invoice can carry it — the form warns, filing is not blocked.
        $xml = (new MyDataSubmitter($this->tenant))->previewXml($this->invoiceOfType('2.2', exemption: 14))->request;

        $this->assertStringContainsString('<vatExemptionCategory>14</vatExemptionCategory>', $xml);
    }

    public function test_a_credit_note_type_is_not_judged(): void
    {
        $original = $this->invoiceOfType('2.2', exemption: 4);
        $original->forceFill(['mydata_mark' => '400001234567890', 'mydata_state' => 'VALID'])->save();
        MyDataMark::create([
            'company_id' => $this->tenant->id, 'invoice_id' => $original->id,
            'mark' => '400001234567890', 'mydata_action' => 'INSERT',
        ]);
        $credit = $this->invoiceOfType('5.1', exemption: 16, code: 2, creditOf: $original);

        $xml = (new MyDataSubmitter($this->tenant))->previewXml($credit)->request;

        $this->assertStringContainsString('<vatExemptionCategory>16</vatExemptionCategory>', $xml);
    }

    public function test_the_form_refuses_to_save_an_impossible_pair(): void
    {
        $type = $this->loginWithType('2.2');

        $component = Livewire::test(CreateInvoice::class)
            ->fillForm($this->formData($type, exemption: 16))
            ->call('create');

        $errors = collect($component->errors()->getMessages())
            ->filter(fn ($messages, string $key): bool => str_ends_with($key, '.vat_exemption_category'));
        $this->assertCount(1, $errors, 'The 0% line reason must carry the conflict error.');
        $this->assertStringContainsString('Αιτία 16 σε τύπο 2.2', $errors->first()[0]);
        $this->assertSame(0, Invoice::query()->withoutGlobalScopes()->count());
    }

    public function test_the_form_saves_the_right_reason_and_only_warns_on_a_suspicious_one(): void
    {
        $type = $this->loginWithType('2.2');

        Livewire::test(CreateInvoice::class)
            ->fillForm($this->formData($type, exemption: 14))
            // The hint turns into the explanation (warn-level: no validation error).
            ->assertSee('Αιτία 14 σε τύπο 2.2')
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(14, (int) InvoiceLine::query()->withoutGlobalScopes()->value('vat_exemption_category'));
    }

    public function test_switching_the_invoice_type_fixes_only_blank_or_impossible_reasons(): void
    {
        $goods = $this->loginWithType('1.2');
        $service = InvoiceType::create(['company_id' => $this->tenant->id, 'code' => 'ENY', 'name' => 'Ενδ. υπηρ.', 'invcount' => 1, 'mydata_type' => '2.2']);
        $domestic = InvoiceType::create(['company_id' => $this->tenant->id, 'code' => 'TPY', 'name' => 'ΤΠΥ', 'invcount' => 1, 'mydata_type' => '2.1']);
        $thirdCountry = InvoiceType::create(['company_id' => $this->tenant->id, 'code' => 'TPT', 'name' => 'Τρίτες', 'invcount' => 1, 'mydata_type' => '2.3']);
        $reason = fn ($component) => collect($component->get('data.lines'))->first()['vat_exemption_category'] ?? null;

        $component = Livewire::test(CreateInvoice::class)->fillForm($this->formData($service, exemption: 4));

        // 2.2 → 2.3 and 2.2 → 1.2: a 4 still fits (a third-country service; a service
        // line on a mixed goods invoice) — never silently rewritten to another reason.
        $component->fillForm(['invoice_type_id' => $thirdCountry->id]);
        $this->assertSame(4, (int) $reason($component));
        $component->fillForm(['invoice_type_id' => $goods->id]);
        $this->assertSame(4, (int) $reason($component));

        // A 14 impossible on a domestic 2.1 is cleared (no suggestion exists there →
        // the operator picks), never left to fail validation on its own.
        $component->set('data.lines.'.array_key_first($component->get('data.lines')).'.vat_exemption_category', 14);
        $component->fillForm(['invoice_type_id' => $domestic->id]);
        $this->assertNull($reason($component));

        // …and a blank reason picks up the next type's suggestion.
        $component->fillForm(['invoice_type_id' => $service->id]);
        $this->assertSame(4, (int) $reason($component));
    }

    public function test_a_reason_that_turns_suspicious_on_a_type_switch_is_called_out_not_rewritten(): void
    {
        // 1.2 → 2.2 with the goods suggestion 14 still on a line: it may be a real
        // goods line on a mixed invoice, so it stays — but the operator is told,
        // loudly, instead of relying on a small hint icon.
        $goods = $this->loginWithType('1.2');
        $service = InvoiceType::create(['company_id' => $this->tenant->id, 'code' => 'ENY', 'name' => 'Ενδ. υπηρ.', 'invcount' => 1, 'mydata_type' => '2.2']);

        $component = Livewire::test(CreateInvoice::class)
            ->fillForm($this->formData($goods, exemption: 14))
            ->fillForm(['invoice_type_id' => $service->id]);

        $this->assertSame(14, (int) (collect($component->get('data.lines'))->first()['vat_exemption_category'] ?? 0));
        $component->assertNotified('Έλεγξε την αιτία απαλλαγής — γραμμή 1');
    }

    private function invoiceOfType(string $mydataType, ?int $exemption, int $code = 1, ?Invoice $creditOf = null): Invoice
    {
        $type = InvoiceType::firstOrCreate(
            ['company_id' => $this->tenant->id, 'code' => 'T'.str_replace('.', '', $mydataType)],
            ['name' => 'Τύπος '.$mydataType, 'invcount' => 1, 'mydata_type' => $mydataType, 'is_credit' => $creditOf !== null],
        );
        $invoice = Invoice::create([
            'company_id' => $this->tenant->id, 'invcode' => $type->code.$code, 'code' => $code, 'local_status' => 'draft',
            'invoice_type_id' => $type->id, 'customer_id' => $this->customer->id,
            'issued_at' => now(), 'header_discount_percent' => 0,
            'credited_invoice_id' => $creditOf?->id,
        ]);
        InvoiceLine::create([
            'company_id' => $this->tenant->id, 'invoice_id' => $invoice->id,
            'qty' => 1, 'vat_percent' => 0, 'net_price' => 100, 'gross_price' => 100,
            'vat_exemption_category' => $exemption,
        ]);

        return $invoice->fresh('lines');
    }

    private function loginWithType(string $mydataType): InvoiceType
    {
        Gate::before(fn () => true);
        $this->actingAs(User::create([
            'name' => 'Op', 'email' => 'op-'.uniqid().'@example.test', 'password' => bcrypt('x'),
        ]));
        Filament::setTenant($this->tenant);

        return InvoiceType::create([
            'company_id' => $this->tenant->id, 'code' => 'ENP', 'name' => 'Ενδοκοιν. παροχή',
            'invcount' => 1, 'mydata_type' => $mydataType,
        ]);
    }

    /** @return array<string, mixed> */
    private function formData(InvoiceType $type, int $exemption): array
    {
        $method = PaymentMethod::create(['company_id' => $this->tenant->id, 'description' => 'Επί πιστώσει', 'due_days' => 30]);

        return [
            'invoice_type_id' => $type->id,
            'customer_id' => $this->customer->id,
            'payment_method_id' => $method->id,
            'lines' => [[
                'product_descr' => 'Hosting', 'qty' => 1, 'price_per_item' => 100, 'discount' => 0,
                'vat_percent' => '0.00', 'vat_exemption_category' => $exemption,
            ]],
        ];
    }
}
