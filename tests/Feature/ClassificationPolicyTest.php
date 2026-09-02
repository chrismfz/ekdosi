<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\InvoiceType;
use App\Models\MyDataMark;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\VatCategory;
use App\Services\MyDataSubmitter;
use App\Support\MyData\ClassificationGuidance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * MYD-006: the business-activity policy drives the §8.6 goods bucket at filing,
 * and a credit note inherits the original document's classification instead of
 * the credit type's generic default. Covers reseller / manufacturer / own-product
 * override / services / correlated-credit through the real submit XML.
 */
class ClassificationPolicyTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private Customer $customer;

    private VatCategory $vat;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Company::create([
            'name' => 'Classify OE',
            'slug' => 'classify-'.uniqid(),
            'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata',
            'mydata_mode' => 'sandbox',
            'afm' => '800561849',
            'mydata_aade_id_sandbox' => 'TESTUSER',
            'mydata_subscription_key_sandbox' => 'TESTKEY',
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
    }

    /** A goods invoice type (1.1) classified E3_561_001 / category1_1 (the merchant seed default). */
    private function goodsType(): InvoiceType
    {
        return InvoiceType::create([
            'company_id' => $this->tenant->id, 'code' => 'TIM', 'name' => 'Τιμολόγιο',
            'invcount' => 1, 'mydata_type' => '1.1',
            'mydata_income_class' => 'E3_561_001', 'mydata_income_class_category' => 'category1_1',
        ]);
    }

    private function invoiceOfType(InvoiceType $type, ?Product $product = null, float $price = 100): Invoice
    {
        $inv = Invoice::create([
            'company_id' => $this->tenant->id, 'invcode' => $type->code.$type->id, 'code' => 1,
            'invoice_type_id' => $type->id, 'customer_id' => $this->customer->id,
            'issued_at' => now(), 'header_discount_percent' => 0,
        ]);
        InvoiceLine::create([
            'company_id' => $this->tenant->id, 'invoice_id' => $inv->id,
            'product_id' => $product?->id, 'qty' => 1, 'vat_percent' => 24, 'price_per_item' => $price,
        ]);

        return $inv->fresh('lines');
    }

    /** @return array<string, float> "E3type|category" => summed amount, from the invoiceSummary. */
    private function summaryClasses(string $xml): array
    {
        $doc = new \DOMDocument;
        $doc->loadXML($xml);
        $out = [];
        foreach ($doc->getElementsByTagNameNS('*', 'incomeClassification') as $node) {
            if ($node->parentNode?->localName !== 'invoiceSummary') {
                continue;
            }
            $type = $node->getElementsByTagNameNS('*', 'classificationType')->item(0)?->textContent;
            $cat = $node->getElementsByTagNameNS('*', 'classificationCategory')->item(0)?->textContent;
            $amount = (float) $node->getElementsByTagNameNS('*', 'amount')->item(0)?->textContent;
            $out["{$type}|{$cat}"] = ($out["{$type}|{$cat}"] ?? 0) + $amount;
        }

        return $out;
    }

    private function file(Invoice $inv): string
    {
        return (new MyDataSubmitter($this->tenant))->previewXml($inv)->request;
    }

    public function test_reseller_files_goods_as_merchandise_category1_1(): void
    {
        $this->tenant->update(['business_activity_type' => ClassificationGuidance::RESELLER]);

        $xml = $this->file($this->invoiceOfType($this->goodsType()));

        $this->assertSame(['E3_561_001|category1_1' => 100.0], $this->summaryClasses($xml));
    }

    public function test_manufacturer_files_own_products_as_category1_2(): void
    {
        // The MYD-006 fix: the SAME goods type files category1_2 for a manufacturer,
        // not the merchandise category1_1 the type default assumes.
        $this->tenant->update(['business_activity_type' => ClassificationGuidance::MANUFACTURER]);

        $xml = $this->file($this->invoiceOfType($this->goodsType()));

        $this->assertSame(['E3_561_001|category1_2' => 100.0], $this->summaryClasses($xml));
    }

    public function test_unset_policy_keeps_the_type_default(): void
    {
        // No policy chosen → no substitution (filing still works pre-go-live; the
        // go-live gate is what forces the choice, not the filing path).
        $xml = $this->file($this->invoiceOfType($this->goodsType()));

        $this->assertSame(['E3_561_001|category1_1' => 100.0], $this->summaryClasses($xml));
    }

    public function test_explicit_product_category_override_wins_over_the_policy(): void
    {
        // A manufacturer that has explicitly classified a product category as
        // merchandise (resold third-party goods) keeps that override — the policy
        // only fills the GENERIC default, it never overrides an explicit choice.
        $this->tenant->update(['business_activity_type' => ClassificationGuidance::MANUFACTURER]);

        $category = ProductCategory::create([
            'company_id' => $this->tenant->id, 'description_short' => 'Μεταπωλούμενα',
            'mydata_income_class_category' => 'category1_1',
        ]);
        $product = Product::create([
            'company_id' => $this->tenant->id, 'description_short' => 'Ανταλλακτικό',
            'product_category_id' => $category->id, 'vat_category_id' => $this->vat->id,
        ]);

        $xml = $this->file($this->invoiceOfType($this->goodsType(), $product));

        $this->assertSame(['E3_561_001|category1_1' => 100.0], $this->summaryClasses($xml));
    }

    public function test_services_line_is_untouched_by_a_manufacturer_policy(): void
    {
        // The policy only substitutes the merchandise default (category1_1); a
        // services type already files category1_3, so a manufacturer's service
        // lines are unaffected.
        $this->tenant->update(['business_activity_type' => ClassificationGuidance::MANUFACTURER]);

        $servicesType = InvoiceType::create([
            'company_id' => $this->tenant->id, 'code' => 'TPY', 'name' => 'ΤΠΥ',
            'invcount' => 1, 'mydata_type' => '11.2',
            'mydata_income_class' => 'E3_561_003', 'mydata_income_class_category' => 'category1_3',
        ]);

        $xml = $this->file($this->invoiceOfType($servicesType));

        $this->assertSame(['E3_561_003|category1_3' => 100.0], $this->summaryClasses($xml));
    }

    public function test_per_line_classification_snapshot_wins_over_type_and_policy(): void
    {
        // MYD-006 bridge: a line's own §8.6 snapshot (stamped by the WHMCS map)
        // is filed VERBATIM — over the invoice-type default AND the business policy.
        // Services type defaults to category1_3, tenant is a manufacturer, yet the
        // stamped category1_1 line files category1_1.
        $this->tenant->update(['business_activity_type' => ClassificationGuidance::MANUFACTURER]);

        $type = InvoiceType::create([
            'company_id' => $this->tenant->id, 'code' => 'TPY', 'name' => 'ΤΠΥ',
            'invcount' => 1, 'mydata_type' => '11.2',
            'mydata_income_class' => 'E3_561_003', 'mydata_income_class_category' => 'category1_3',
        ]);
        $inv = Invoice::create([
            'company_id' => $this->tenant->id, 'invcode' => 'TPY-SNAP', 'code' => 1,
            'invoice_type_id' => $type->id, 'customer_id' => $this->customer->id,
            'issued_at' => now(), 'header_discount_percent' => 0,
        ]);
        InvoiceLine::create([
            'company_id' => $this->tenant->id, 'invoice_id' => $inv->id,
            'qty' => 1, 'vat_percent' => 24, 'price_per_item' => 100,
            // The snapshot the WHMCS bridge would stamp.
            'mydata_income_class_category' => 'category1_1',
        ]);

        // E3 class stays the type's (snapshot set only the bucket); the bucket is
        // the stamped category1_1, not the type's category1_3 nor a policy value.
        $this->assertSame(['E3_561_003|category1_1' => 100.0], $this->summaryClasses($this->file($inv->fresh('lines'))));
    }

    public function test_correlated_credit_inherits_the_original_classification(): void
    {
        // MYD-006 acceptance: a correlated (5.1) credit reverses the ORIGINAL income
        // line, so it files the original type's E3 class + bucket — NOT the credit
        // type's generic E3_561_001/category1_3 default. Original here is an
        // intra-community goods sale (E3_561_005 / category1_1).
        $original = InvoiceType::create([
            'company_id' => $this->tenant->id, 'code' => 'TID', 'name' => 'Τιμ. ενδοκ.',
            'invcount' => 1, 'mydata_type' => '1.1',
            'mydata_income_class' => 'E3_561_005', 'mydata_income_class_category' => 'category1_1',
        ]);
        $originalInvoice = $this->invoiceOfType($original);
        MyDataMark::create([
            'company_id' => $this->tenant->id, 'invoice_id' => $originalInvoice->id,
            'mark' => '400000000000111', 'mydata_action' => 'INSERT',
        ]);

        $creditType = InvoiceType::create([
            'company_id' => $this->tenant->id, 'code' => 'PIS', 'name' => 'Πιστωτικό Συσχ.',
            'invcount' => 1, 'mydata_type' => '5.1', 'is_credit' => true,
            'mydata_income_class' => 'E3_561_001', 'mydata_income_class_category' => 'category1_3',
        ]);
        $credit = Invoice::create([
            'company_id' => $this->tenant->id, 'invcode' => 'PIS1', 'code' => 1,
            'invoice_type_id' => $creditType->id, 'customer_id' => $this->customer->id,
            'issued_at' => now(), 'header_discount_percent' => 0,
            'credited_invoice_id' => $originalInvoice->id,
        ]);
        InvoiceLine::create([
            'company_id' => $this->tenant->id, 'invoice_id' => $credit->id,
            'qty' => 1, 'vat_percent' => 24, 'price_per_item' => 100,
        ]);

        $xml = $this->file($credit->fresh('lines'));

        // Inherited from the original, not the credit type's own E3_561_001/category1_3.
        $this->assertSame(['E3_561_005|category1_1' => 100.0], $this->summaryClasses($xml));
    }

    public function test_non_correlated_credit_also_inherits_the_original_classification(): void
    {
        // A 5.2 non-correlated credit reverses income too, so it inherits the
        // original's classification (no correlation / MARK needed).
        $original = InvoiceType::create([
            'company_id' => $this->tenant->id, 'code' => 'TID2', 'name' => 'Τιμ.',
            'invcount' => 1, 'mydata_type' => '1.1',
            'mydata_income_class' => 'E3_561_005', 'mydata_income_class_category' => 'category1_1',
        ]);
        $originalInvoice = $this->invoiceOfType($original);

        $creditType = InvoiceType::create([
            'company_id' => $this->tenant->id, 'code' => 'PIS2', 'name' => 'Πιστωτικό',
            'invcount' => 1, 'mydata_type' => '5.2', 'is_credit' => true,
            'mydata_income_class' => 'E3_561_001', 'mydata_income_class_category' => 'category1_3',
        ]);
        $credit = Invoice::create([
            'company_id' => $this->tenant->id, 'invcode' => 'PIS2-1', 'code' => 1,
            'invoice_type_id' => $creditType->id, 'customer_id' => $this->customer->id,
            'issued_at' => now(), 'header_discount_percent' => 0,
            'credited_invoice_id' => $originalInvoice->id,
        ]);
        InvoiceLine::create([
            'company_id' => $this->tenant->id, 'invoice_id' => $credit->id,
            'qty' => 1, 'vat_percent' => 24, 'price_per_item' => 100,
        ]);

        $xml = $this->file($credit->fresh('lines'));

        $this->assertSame(['E3_561_005|category1_1' => 100.0], $this->summaryClasses($xml));
    }

    public function test_services_credit_inherits_the_retail_e3_class_of_its_original(): void
    {
        // Review guard (MYD-006): the credit E3 CLASS follows the ORIGINAL, not the
        // credit type's own default. A live services tenant credits an ΑΠΥ (11.2,
        // E3_561_003) with a ΠΙΣ configured E3_561_001 — the credit now files
        // E3_561_003 (what it reverses), pinning the intended change so a future
        // edit to baseClassificationFor can't silently regress live credit filings.
        $original = InvoiceType::create([
            'company_id' => $this->tenant->id, 'code' => 'APY', 'name' => 'ΑΠΥ',
            'invcount' => 1, 'mydata_type' => '11.2',
            'mydata_income_class' => 'E3_561_003', 'mydata_income_class_category' => 'category1_3',
        ]);
        $originalInvoice = $this->invoiceOfType($original);
        MyDataMark::create([
            'company_id' => $this->tenant->id, 'invoice_id' => $originalInvoice->id,
            'mark' => '400000000000222', 'mydata_action' => 'INSERT',
        ]);

        $creditType = InvoiceType::create([
            'company_id' => $this->tenant->id, 'code' => 'PISS', 'name' => 'Πιστωτικό υπηρ.',
            'invcount' => 1, 'mydata_type' => '5.1', 'is_credit' => true,
            'mydata_income_class' => 'E3_561_001', 'mydata_income_class_category' => 'category1_3',
        ]);
        $credit = Invoice::create([
            'company_id' => $this->tenant->id, 'invcode' => 'PISS1', 'code' => 1,
            'invoice_type_id' => $creditType->id, 'customer_id' => $this->customer->id,
            'issued_at' => now(), 'header_discount_percent' => 0,
            'credited_invoice_id' => $originalInvoice->id,
        ]);
        InvoiceLine::create([
            'company_id' => $this->tenant->id, 'invoice_id' => $credit->id,
            'qty' => 1, 'vat_percent' => 24, 'price_per_item' => 100,
        ]);

        $this->assertSame(['E3_561_003|category1_3' => 100.0], $this->summaryClasses($this->file($credit->fresh('lines'))));
    }

    public function test_manufacturer_credit_stays_consistent_with_the_original_bucket(): void
    {
        // Review guard (MYD-006): the business policy is applied to credit lines the
        // SAME way as to the original, so a manufacturer's free-text goods credit
        // files category1_2 — exactly what the original debit filed. (Skipping the
        // policy on credits would create the mismatch, not avoid it.)
        $this->tenant->update(['business_activity_type' => ClassificationGuidance::MANUFACTURER]);

        // Original: free-text goods line → files E3_561_001/category1_2 (policy).
        $originalInvoice = $this->invoiceOfType($this->goodsType());
        $this->assertSame(['E3_561_001|category1_2' => 100.0], $this->summaryClasses($this->file($originalInvoice)));
        MyDataMark::create([
            'company_id' => $this->tenant->id, 'invoice_id' => $originalInvoice->id,
            'mark' => '400000000000333', 'mydata_action' => 'INSERT',
        ]);

        $creditType = InvoiceType::create([
            'company_id' => $this->tenant->id, 'code' => 'PISM', 'name' => 'Πιστωτικό',
            'invcount' => 1, 'mydata_type' => '5.1', 'is_credit' => true,
            'mydata_income_class' => 'E3_561_001', 'mydata_income_class_category' => 'category1_3',
        ]);
        $credit = Invoice::create([
            'company_id' => $this->tenant->id, 'invcode' => 'PISM1', 'code' => 1,
            'invoice_type_id' => $creditType->id, 'customer_id' => $this->customer->id,
            'issued_at' => now(), 'header_discount_percent' => 0,
            'credited_invoice_id' => $originalInvoice->id,
        ]);
        InvoiceLine::create([
            'company_id' => $this->tenant->id, 'invoice_id' => $credit->id,
            'qty' => 1, 'vat_percent' => 24, 'price_per_item' => 100,
        ]);

        // Credit inherits the original goods type (E3_561_001/category1_1) then the
        // manufacturer policy bumps the bucket to category1_2 — matching the debit.
        $this->assertSame(['E3_561_001|category1_2' => 100.0], $this->summaryClasses($this->file($credit->fresh('lines'))));
    }
}
