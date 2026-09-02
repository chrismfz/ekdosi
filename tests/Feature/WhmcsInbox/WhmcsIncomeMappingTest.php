<?php

namespace Tests\Feature\WhmcsInbox;

use App\Models\Company;
use App\Models\Customer;
use App\Models\InvoiceType;
use App\Models\PaymentMethod;
use App\Models\PendingWhmcsInvoice;
use App\Models\VatCategory;
use App\Models\WhmcsIncomeMap;
use App\Services\WhmcsInbox\WhmcsIncomeClassifier;
use App\Services\WhmcsInbox\WhmcsInvoiceMapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * MYD-006 bridge: a WHMCS product GROUP (or product override) declares «τι είναι»,
 * and the mapper stamps that §8.6 bucket onto each ingested line — WITHOUT touching
 * the WHMCS amount (the legal figure). Covers the classifier's product→group→none
 * resolution and the mapper's stamp + amount guardrail.
 */
class WhmcsIncomeMappingTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private VatCategory $vat24;

    private InvoiceType $invoiceType;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Company::create([
            'name' => 'Bridge OE', 'slug' => 'br-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
        $this->vat24 = VatCategory::create([
            'company_id' => $this->tenant->id, 'name' => 'ΦΠΑ 24%', 'rate' => 24.00, 'is_default' => true,
        ]);
        $pm = PaymentMethod::create(['company_id' => $this->tenant->id, 'name' => 'Bank', 'due_days' => 0, 'is_active' => true]);
        $this->invoiceType = InvoiceType::create([
            'company_id' => $this->tenant->id, 'name' => 'ΤΠΥ', 'code' => 'ΤΠΥ', 'invcount' => 0,
            'payment_method_id' => $pm->id,
        ]);
        $this->customer = Customer::create([
            'company_id' => $this->tenant->id, 'name' => 'ΑΚΜΕ ΑΕ', 'afm' => '123456789',
            'address1' => 'Πατησίων 1', 'city' => 'Αθήνα', 'postcode' => '10101', 'country' => 'GR',
        ]);
    }

    private function groupMap(int $gid, string $category): WhmcsIncomeMap
    {
        return WhmcsIncomeMap::create([
            'company_id' => $this->tenant->id, 'scope' => WhmcsIncomeMap::SCOPE_GROUP,
            'whmcs_key' => $gid, 'income_class_category' => $category, 'label' => 'Web Hosting',
        ]);
    }

    private function mapPayload(array $items): array
    {
        $pending = PendingWhmcsInvoice::create([
            'company_id' => $this->tenant->id, 'whmcs_invoice_id' => 4242,
            'payload' => ['invoiceid' => 4242, 'userid' => 5, 'date' => '2026-09-02', 'total' => '124.00',
                'items' => ['item' => $items]],
            'match_reason' => PendingWhmcsInvoice::REASON_LINKED,
            'status' => PendingWhmcsInvoice::STATUS_PENDING_REVIEW,
        ]);

        return app(WhmcsInvoiceMapper::class)->map($this->tenant, $pending, $this->customer, $this->invoiceType);
    }

    public function test_classifier_resolves_product_override_then_group_then_none(): void
    {
        $this->groupMap(3, 'category1_3');                       // Web Hosting group → services
        WhmcsIncomeMap::create([                                  // one package overridden → goods
            'company_id' => $this->tenant->id, 'scope' => WhmcsIncomeMap::SCOPE_PRODUCT,
            'whmcs_key' => 42, 'income_class_category' => 'category1_1',
        ]);

        $c = WhmcsIncomeClassifier::forCompany($this->tenant->id);

        // Product override wins over its group.
        $this->assertSame([null, 'category1_1'], $c->resolve(42, 3));
        // A different package in the group inherits the group.
        $this->assertSame([null, 'category1_3'], $c->resolve(99, 3));
        // Unmapped product + unmapped group → nothing (submitter resolves).
        $this->assertSame([null, null], $c->resolve(99, 7));
        $this->assertSame([null, null], $c->resolve(0, 0));
    }

    public function test_mapper_stamps_the_group_classification_on_the_line(): void
    {
        $this->groupMap(3, 'category1_3');

        $result = $this->mapPayload([
            ['description' => 'Web Hosting - Personal2 - loopmonkee.com', 'amount' => '124.00', 'taxed' => '1',
                'whmcs_product_id' => 42, 'whmcs_group_id' => 3],
        ]);

        $line = $result['lines'][0];
        $this->assertSame('category1_3', $line['mydata_income_class_category']);
        $this->assertNull($line['mydata_income_class']);
        // Guardrail: the WHMCS amount is untouched — the mapping only tags «τι είναι».
        $this->assertSame(100.0, $line['net_price']);
        $this->assertSame(124.0, $line['gross_price']);
    }

    public function test_product_override_beats_group_at_ingest(): void
    {
        $this->groupMap(3, 'category1_3');                        // group = services
        WhmcsIncomeMap::create([                                  // this package = goods
            'company_id' => $this->tenant->id, 'scope' => WhmcsIncomeMap::SCOPE_PRODUCT,
            'whmcs_key' => 42, 'income_class_category' => 'category1_1',
        ]);

        $line = $this->mapPayload([
            ['description' => 'Special box', 'amount' => '124.00', 'taxed' => '1',
                'whmcs_product_id' => 42, 'whmcs_group_id' => 3],
        ])['lines'][0];

        $this->assertSame('category1_1', $line['mydata_income_class_category']);
    }

    public function test_unmapped_line_or_old_plugin_stamps_nothing(): void
    {
        // No map rows at all, and a payload with NO product/group ids (old plugin) —
        // the line carries no snapshot, so the submitter falls back as before.
        $line = $this->mapPayload([
            ['description' => 'Domain - doxanskopou.com - 2 Χρόνια', 'amount' => '124.00', 'taxed' => '1'],
        ])['lines'][0];

        $this->assertNull($line['mydata_income_class_category']);
        $this->assertNull($line['mydata_income_class']);
        $this->assertSame(100.0, $line['net_price']); // amount still mapped correctly
    }
}
