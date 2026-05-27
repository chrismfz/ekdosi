<?php

namespace Tests\Feature\WhmcsInbox;

use App\Models\Company;
use App\Models\Customer;
use App\Models\InvoiceType;
use App\Models\PaymentMethod;
use App\Models\PendingWhmcsInvoice;
use App\Models\VatCategory;
use App\Services\WhmcsInbox\WhmcsInvoiceMapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Stage B-2 — WhmcsInvoiceMapper: WHMCS GetInvoice payload → ekdosi
 * Invoice/Line shape. Pure logic, no DB writes.
 */
class WhmcsInvoiceMapperTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;
    private VatCategory $vat24;
    private PaymentMethod $pm;
    private InvoiceType $invoiceType;
    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Company::create([
            'name' => 'T',
            'slug' => 't-'.uniqid(),
            'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata',
            'mydata_mode' => 'off',
        ]);
        $this->vat24 = VatCategory::create([
            'company_id' => $this->tenant->id,
            'name' => 'ΦΠΑ 24%',
            'rate' => 24.00,
            'is_default' => true,
        ]);
        $this->pm = PaymentMethod::create([
            'company_id' => $this->tenant->id,
            'name' => 'Bank',
            'due_days' => 0,
            'is_active' => true,
        ]);
        $this->invoiceType = InvoiceType::create([
            'company_id' => $this->tenant->id,
            'name' => 'ΤΠΥ',
            'code' => 'ΤΠΥ',
            'invcount' => 0,
            'payment_method_id' => $this->pm->id,
        ]);
        $this->customer = Customer::create([
            'company_id' => $this->tenant->id,
            'name' => 'ΑΚΜΕ ΑΕ',
            'afm' => '123456789',
            'address1' => 'Πατησίων 1',
            'city' => 'Αθήνα',
            'postcode' => '10101',
            'country' => 'GR',
            'occupation' => 'Σύμβουλος',
        ]);
    }

    private function makePending(array $payload): PendingWhmcsInvoice
    {
        return PendingWhmcsInvoice::create([
            'company_id'       => $this->tenant->id,
            'whmcs_invoice_id' => $payload['invoiceid'] ?? 7777,
            'payload'          => $payload,
            'match_reason'     => PendingWhmcsInvoice::REASON_LINKED,
            'status'           => PendingWhmcsInvoice::STATUS_PENDING_REVIEW,
        ]);
    }

    public function test_maps_single_gross_price_to_net_using_default_vat(): void
    {
        $pending = $this->makePending([
            'invoiceid' => 1001,
            'userid'    => 555,
            'date'      => '2026-05-20',
            'total'     => '124.00',
            'items'     => ['item' => [
                ['description' => 'Domain ekdosi.gr 1 έτος', 'amount' => '124.00', 'taxed' => '1'],
            ]],
        ]);

        $result = app(WhmcsInvoiceMapper::class)->map($this->tenant, $pending, $this->customer, $this->invoiceType);

        $this->assertCount(1, $result['lines']);
        $line = $result['lines'][0];
        $this->assertSame('Domain ekdosi.gr 1 έτος', $line['description']);
        $this->assertSame(100.0, $line['price_per_item']);       // 124 / 1.24
        $this->assertSame(100.0, $line['net_price']);
        $this->assertSame(124.0, $line['gross_price']);
        $this->assertSame(24.0, $line['vat_percent']);

        $this->assertSame(100.0, $result['totals']['net_total']);
        $this->assertSame(24.0, $result['totals']['vat_total']);
        $this->assertSame(124.0, $result['totals']['gross_total']);
    }

    public function test_untaxed_line_keeps_amount_as_net_and_gross(): void
    {
        $pending = $this->makePending([
            'invoiceid' => 1002,
            'items' => ['item' => [
                ['description' => 'Πληρωμή', 'amount' => '50.00', 'taxed' => '0'],
            ]],
        ]);

        $line = app(WhmcsInvoiceMapper::class)
            ->map($this->tenant, $pending, $this->customer, $this->invoiceType)['lines'][0];

        $this->assertSame(50.0, $line['net_price']);
        $this->assertSame(50.0, $line['gross_price']);
        $this->assertSame(0.0, $line['vat_percent']);
    }

    public function test_snapshots_customer_fields_into_header(): void
    {
        $pending = $this->makePending([
            'invoiceid' => 1003,
            'items' => ['item' => [
                ['description' => 'X', 'amount' => '124.00', 'taxed' => '1'],
            ]],
        ]);

        $header = app(WhmcsInvoiceMapper::class)
            ->map($this->tenant, $pending, $this->customer, $this->invoiceType)['header'];

        $this->assertSame('ΑΚΜΕ ΑΕ', $header['company_name']);
        $this->assertSame('123456789', $header['vat_no']);
        $this->assertSame('Πατησίων 1', $header['address1']);
        $this->assertSame('Αθήνα', $header['city']);
        $this->assertSame('10101', $header['postcode']);
        $this->assertSame('GR', $header['country']);
        $this->assertSame('Σύμβουλος', $header['occupation']);
        $this->assertSame($this->customer->id, $header['customer_id']);
        $this->assertSame($this->invoiceType->id, $header['invoice_type_id']);
        $this->assertSame($this->pm->id, $header['payment_method_id']);
    }

    public function test_normalises_single_item_object_shape(): void
    {
        // WHMCS returns single items as object not array.
        $pending = $this->makePending([
            'invoiceid' => 1004,
            'items' => ['item' => ['description' => 'Only one', 'amount' => '12.40', 'taxed' => '1']],
        ]);

        $lines = app(WhmcsInvoiceMapper::class)
            ->map($this->tenant, $pending, $this->customer, $this->invoiceType)['lines'];

        $this->assertCount(1, $lines);
        $this->assertSame('Only one', $lines[0]['description']);
    }

    public function test_mixed_taxed_and_untaxed_lines_produce_split_vat_breakdown(): void
    {
        $pending = $this->makePending([
            'invoiceid' => 1005,
            'items' => ['item' => [
                ['description' => 'A', 'amount' => '124.00', 'taxed' => '1'],   // 100 net + 24 vat
                ['description' => 'B', 'amount' => '50.00',  'taxed' => '0'],   // 50 net, 0 vat
            ]],
        ]);

        $totals = app(WhmcsInvoiceMapper::class)
            ->map($this->tenant, $pending, $this->customer, $this->invoiceType)['totals'];

        $this->assertSame(150.0, $totals['net_total']);
        $this->assertSame(24.0, $totals['vat_total']);
        $this->assertSame(174.0, $totals['gross_total']);

        $breakdown = $totals['vat_breakdown'];
        $this->assertCount(2, $breakdown);
        // Ascending sort: 0% first, then 24%.
        $this->assertSame(0.0, $breakdown[0]['rate']);
        $this->assertSame(50.0, $breakdown[0]['gross']);
        $this->assertSame(24.0, $breakdown[1]['rate']);
        $this->assertSame(124.0, $breakdown[1]['gross']);
    }

    public function test_skips_empty_descriptions(): void
    {
        $pending = $this->makePending([
            'invoiceid' => 1006,
            'items' => ['item' => [
                ['description' => 'Real', 'amount' => '124.00', 'taxed' => '1'],
                ['description' => '',     'amount' => '50.00',  'taxed' => '1'],   // skipped
                ['description' => '   ',  'amount' => '20.00',  'taxed' => '1'],   // skipped
            ]],
        ]);

        $lines = app(WhmcsInvoiceMapper::class)
            ->map($this->tenant, $pending, $this->customer, $this->invoiceType)['lines'];

        $this->assertCount(1, $lines);
        $this->assertSame('Real', $lines[0]['description']);
    }

    public function test_rejects_cross_tenant_customer(): void
    {
        $otherTenant = Company::create([
            'name' => 'Other', 'slug' => 'o-'.uniqid(),
            'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
        $otherCustomer = Customer::create([
            'company_id' => $otherTenant->id,
            'name' => 'Outsider',
        ]);

        $pending = $this->makePending([
            'invoiceid' => 9999,
            'items' => ['item' => [['description' => 'X', 'amount' => '1', 'taxed' => '1']]],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('different tenant');
        app(WhmcsInvoiceMapper::class)->map($this->tenant, $pending, $otherCustomer, $this->invoiceType);
    }

    public function test_throws_when_tenant_has_no_default_vat_category(): void
    {
        // Demote the default.
        $this->vat24->update(['is_default' => false]);

        $pending = $this->makePending([
            'invoiceid' => 1007,
            'items' => ['item' => [['description' => 'X', 'amount' => '1', 'taxed' => '1']]],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('no default VAT category');
        app(WhmcsInvoiceMapper::class)->map($this->tenant, $pending, $this->customer, $this->invoiceType);
    }

    public function test_carries_whmcs_source_metadata(): void
    {
        $pending = $this->makePending([
            'invoiceid' => 12345,
            'userid'    => 777,
            'date'      => '2026-05-20',
            'total'     => '1240.00',
            'items' => ['item' => [['description' => 'X', 'amount' => '1240.00', 'taxed' => '1']]],
        ]);

        $source = app(WhmcsInvoiceMapper::class)
            ->map($this->tenant, $pending, $this->customer, $this->invoiceType)['source'];

        $this->assertSame(12345, $source['whmcs_invoice_id']);
        $this->assertSame(777, $source['whmcs_userid']);
        $this->assertSame('2026-05-20', $source['whmcs_date']);
        $this->assertSame(1240.0, $source['whmcs_total']);
    }
}
