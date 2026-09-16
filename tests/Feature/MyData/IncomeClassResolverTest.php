<?php

namespace Tests\Feature\MyData;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\InvoiceType;
use App\Models\MyDataMark;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\VatCategory;
use App\Support\MyData\ClassificationGuidance;
use App\Support\MyData\IncomeClassResolver;
use App\Support\MyData\MarkDetail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * IncomeClassResolver is the SINGLE source the filing path AND the read-only
 * display surfaces («Έλεγχος ΜΑΡΚ», invoice-line table) share. The filing side is
 * pinned by ClassificationPolicyTest (through the real submit XML); this pins the
 * resolver directly + the display shape (MarkDetail::fromInvoice) so a local
 * invoice shows the SAME (E3 class, category) it files.
 */
class IncomeClassResolverTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private Customer $customer;

    private VatCategory $vat;

    private IncomeClassResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Company::create([
            'name' => 'Resolve OE',
            'slug' => 'resolve-'.uniqid(),
            'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata',
            'mydata_mode' => 'sandbox',
            'afm' => '800561849',
        ]);

        $this->customer = Customer::create([
            'company_id' => $this->tenant->id,
            'name' => 'B2B πελάτης',
            'afm' => '123456789',
        ]);

        $this->vat = VatCategory::create([
            'company_id' => $this->tenant->id,
            'description' => '24%', 'rate' => 24, 'is_default' => true,
        ]);

        $this->resolver = app(IncomeClassResolver::class);
    }

    private function servicesType(): InvoiceType
    {
        return InvoiceType::create([
            'company_id' => $this->tenant->id, 'code' => 'TPY', 'name' => 'ΤΠΥ',
            'invcount' => 1, 'mydata_type' => '2.1',
            'mydata_income_class' => 'E3_561_001', 'mydata_income_class_category' => 'category1_3',
        ]);
    }

    private function goodsType(): InvoiceType
    {
        return InvoiceType::create([
            'company_id' => $this->tenant->id, 'code' => 'TIM', 'name' => 'Τιμολόγιο',
            'invcount' => 1, 'mydata_type' => '1.1',
            'mydata_income_class' => 'E3_561_001', 'mydata_income_class_category' => 'category1_1',
        ]);
    }

    private function invoiceOf(InvoiceType $type, ?Product $product = null, array $lineExtra = []): Invoice
    {
        $inv = Invoice::create([
            'company_id' => $this->tenant->id, 'invcode' => $type->code.$type->id.'-'.substr(uniqid(), -6), 'code' => 1,
            'invoice_type_id' => $type->id, 'customer_id' => $this->customer->id,
            'issued_at' => now(), 'header_discount_percent' => 0,
        ]);
        InvoiceLine::create(array_merge([
            'company_id' => $this->tenant->id, 'invoice_id' => $inv->id,
            'product_id' => $product?->id, 'product_descr' => 'Υπηρεσία δοκιμής', 'qty' => 1, 'vat_percent' => 24,
            'price_per_item' => 100, 'net_price' => 100, 'gross_price' => 124,
        ], $lineExtra));

        return $inv->fresh(['lines.product.productCategory', 'invoiceType', 'company']);
    }

    private function firstLineClass(Invoice $invoice): array
    {
        return $this->resolver->resolve($invoice, $invoice->lines->first());
    }

    public function test_services_type_default_resolves_to_its_e3_pair(): void
    {
        $this->assertSame(
            ['E3_561_001', 'category1_3'],
            $this->firstLineClass($this->invoiceOf($this->servicesType())),
        );
    }

    public function test_product_category_overrides_the_bucket(): void
    {
        $category = ProductCategory::create([
            'company_id' => $this->tenant->id, 'description_short' => 'Εμπορεύματα',
            'mydata_income_class_category' => 'category1_1',
        ]);
        $product = Product::create([
            'company_id' => $this->tenant->id, 'description_short' => 'Είδος',
            'product_category_id' => $category->id, 'vat_category_id' => $this->vat->id,
        ]);

        // The E3 class stays the type's; only the §8.6 bucket is overridden.
        $this->assertSame(
            ['E3_561_001', 'category1_1'],
            $this->firstLineClass($this->invoiceOf($this->servicesType(), $product)),
        );
    }

    public function test_per_line_snapshot_wins_over_type_and_policy(): void
    {
        $this->tenant->update(['business_activity_type' => ClassificationGuidance::MANUFACTURER]);

        $this->assertSame(
            ['E3_561_001', 'category1_1'],
            $this->firstLineClass($this->invoiceOf($this->servicesType(), null, [
                'mydata_income_class_category' => 'category1_1',
            ])),
        );
    }

    public function test_manufacturer_policy_bumps_the_merchandise_default_to_own_products(): void
    {
        $this->tenant->update(['business_activity_type' => ClassificationGuidance::MANUFACTURER]);

        // Goods type defaults to the merchandise bucket (category1_1) with no
        // explicit source → the policy substitutes category1_2 (own products).
        $this->assertSame(
            ['E3_561_001', 'category1_2'],
            $this->firstLineClass($this->invoiceOf($this->goodsType())),
        );
    }

    public function test_credit_note_inherits_the_original_classification(): void
    {
        $originalType = InvoiceType::create([
            'company_id' => $this->tenant->id, 'code' => 'TID', 'name' => 'Τιμ. ενδοκ.',
            'invcount' => 1, 'mydata_type' => '1.1',
            'mydata_income_class' => 'E3_561_005', 'mydata_income_class_category' => 'category1_1',
        ]);
        $original = $this->invoiceOf($originalType);
        MyDataMark::create([
            'company_id' => $this->tenant->id, 'invoice_id' => $original->id,
            'mark' => '400000000000111', 'mydata_action' => 'INSERT',
        ]);

        $creditType = InvoiceType::create([
            'company_id' => $this->tenant->id, 'code' => 'PIS', 'name' => 'Πιστωτικό',
            'invcount' => 1, 'mydata_type' => '5.1', 'is_credit' => true,
            'mydata_income_class' => 'E3_561_001', 'mydata_income_class_category' => 'category1_3',
        ]);
        $credit = Invoice::create([
            'company_id' => $this->tenant->id, 'invcode' => 'PIS1', 'code' => 1,
            'invoice_type_id' => $creditType->id, 'customer_id' => $this->customer->id,
            'issued_at' => now(), 'header_discount_percent' => 0,
            'credited_invoice_id' => $original->id,
        ]);
        InvoiceLine::create([
            'company_id' => $this->tenant->id, 'invoice_id' => $credit->id,
            'qty' => 1, 'vat_percent' => 24, 'price_per_item' => 100, 'net_price' => 100, 'gross_price' => 124,
        ]);

        $this->assertSame(
            ['E3_561_005', 'category1_1'],
            $this->firstLineClass($credit->fresh(['lines.product.productCategory', 'invoiceType', 'company'])),
        );
    }

    public function test_mark_detail_populates_the_classification_on_local_lines(): void
    {
        // Q1: our own filed invoice now carries the E3 classification in the MARK
        // detail line (a discreet sub-line under the description), with labels.
        $invoice = $this->invoiceOf($this->servicesType());
        $invoice->update(['mydata_mark' => '400000000000999', 'mydata_state' => 'VALID']);

        $doc = MarkDetail::fromInvoice($invoice->fresh(['lines.product.productCategory', 'invoiceType', 'company']));

        $cls = $doc['lines'][0]['classifications'];
        $this->assertCount(1, $cls);
        $this->assertSame('E3_561_001', $cls[0]['type']);
        $this->assertSame('category1_3', $cls[0]['category']);
        $this->assertNotNull($cls[0]['typeLabel']);       // the Greek label resolves
        $this->assertNotNull($cls[0]['categoryLabel']);
        $this->assertSame(100.0, $cls[0]['amount']);
        // The product description is kept — classification is ADDED, not instead.
        $this->assertNotEmpty($doc['lines'][0]['itemDescr'] ?? null);
    }

    public function test_type_without_income_class_yields_no_classification(): void
    {
        // A delivery-note-style type with no income class → the display shows no
        // E3 sub-line (nothing to file), rather than an empty/garbage pair.
        $deliveryType = InvoiceType::create([
            'company_id' => $this->tenant->id, 'code' => 'DAP', 'name' => 'Δελτίο αποστολής',
            'invcount' => 1, 'mydata_type' => '9.3',
            'mydata_income_class' => null, 'mydata_income_class_category' => null,
        ]);

        $this->assertSame([null, null], $this->firstLineClass($this->invoiceOf($deliveryType)));

        $invoice = $this->invoiceOf($deliveryType);
        $invoice->update(['mydata_mark' => '400000000000888']);
        $doc = MarkDetail::fromInvoice($invoice->fresh(['lines.product.productCategory', 'invoiceType', 'company']));
        $this->assertSame([], $doc['lines'][0]['classifications']);
    }
}
