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

    private VatCategory $vat0;

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
        // 0%-rate row provided by default for the tests that exercise
        // taxed=0 lines. Tests that explicitly assert the "throw when
        // no 0%-rate category exists" behavior delete this row at the
        // top of the test.
        $this->vat0 = VatCategory::create([
            'company_id' => $this->tenant->id,
            'name' => 'ΦΠΑ 0%',
            'rate' => 0.00,
            'is_default' => false,
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
            'company_id' => $this->tenant->id,
            'whmcs_invoice_id' => $payload['invoiceid'] ?? 7777,
            'payload' => $payload,
            'match_reason' => PendingWhmcsInvoice::REASON_LINKED,
            'status' => PendingWhmcsInvoice::STATUS_PENDING_REVIEW,
        ]);
    }

    public function test_maps_single_gross_price_to_net_using_default_vat(): void
    {
        $pending = $this->makePending([
            'invoiceid' => 1001,
            'userid' => 555,
            'date' => '2026-05-20',
            'total' => '124.00',
            'items' => ['item' => [
                ['description' => 'Domain ekdosi.gr 1 έτος', 'amount' => '124.00', 'taxed' => '1'],
            ]],
        ]);

        $result = app(WhmcsInvoiceMapper::class)->map($this->tenant, $pending, $this->customer, $this->invoiceType);

        $this->assertCount(1, $result['lines']);
        $line = $result['lines'][0];
        $this->assertSame('Domain ekdosi.gr 1 έτος', $line['product_descr']);
        $this->assertSame(100.0, $line['price_per_item']);       // 124 / 1.24
        $this->assertSame(100.0, $line['net_price']);
        $this->assertSame(124.0, $line['gross_price']);
        $this->assertSame(24.0, $line['vat_percent']);

        $this->assertSame(100.0, $result['totals']['net_total']);
        $this->assertSame(24.0, $result['totals']['vat_total']);
        $this->assertSame(124.0, $result['totals']['gross_total']);
    }

    public function test_tax_exclusive_tenant_treats_amount_as_net(): void
    {
        // G3: when the WHMCS instance is tax-exclusive, the line amount IS the
        // net — VAT must be ADDED, not divided out. €100 @ 24% → gross €124.
        $this->tenant->forceFill(['whmcs_amount_includes_tax' => false])->save();

        $pending = $this->makePending([
            'invoiceid' => 1002,
            'userid' => 555,
            'date' => '2026-05-20',
            'total' => '100.00',
            'items' => ['item' => [
                ['description' => 'Service (net)', 'amount' => '100.00', 'taxed' => '1'],
            ]],
        ]);

        $line = app(WhmcsInvoiceMapper::class)
            ->map($this->tenant, $pending, $this->customer, $this->invoiceType)['lines'][0];

        $this->assertSame(100.0, $line['net_price'], 'amount taken as net');
        $this->assertSame(124.0, $line['gross_price'], 'VAT added on top');
    }

    public function test_payload_breakdown_detects_net_amounts_overriding_a_wrong_toggle(): void
    {
        // The real #31476 case: tenant toggle defaults to includes_tax=TRUE
        // (gross), but the WHMCS payload's own breakdown proves the line
        // amounts are NET (subtotal 19 + tax 4.56 == total 23.56). The mapper
        // must TRUST the payload and add VAT on top — not divide it out.
        $this->tenant->forceFill(['whmcs_amount_includes_tax' => true])->save();

        $pending = $this->makePending([
            'invoiceid' => 31476,
            'userid' => 59,
            'date' => '2026-05-06',
            'subtotal' => '19.00',
            'tax' => '4.56',
            'taxrate' => '24.000',
            'total' => '23.56',
            'items' => ['item' => [
                ['description' => 'Ανανέωση Domain - nutriwellness.gr', 'amount' => '19.00', 'taxed' => '1'],
            ]],
        ]);

        $result = app(WhmcsInvoiceMapper::class)
            ->map($this->tenant, $pending, $this->customer, $this->invoiceType);

        $this->assertSame(19.0, $result['lines'][0]['net_price'], 'amount detected as net from payload');
        $this->assertSame(23.56, $result['lines'][0]['gross_price'], 'VAT added on top → matches WHMCS total');
    }

    public function test_payload_breakdown_detects_gross_amounts(): void
    {
        // The other direction: subtotal already includes the tax (subtotal ==
        // total), so the line amount is GROSS → back out the net. €124 @ 24%
        // → net €100. Toggle says net, but the payload wins.
        $this->tenant->forceFill(['whmcs_amount_includes_tax' => false])->save();

        $pending = $this->makePending([
            'invoiceid' => 31477,
            'userid' => 60,
            'date' => '2026-05-06',
            'subtotal' => '124.00',
            'tax' => '24.00',
            'taxrate' => '24.000',
            'total' => '124.00',     // tax already inside → gross amounts
            'items' => ['item' => [
                ['description' => 'Gross line', 'amount' => '124.00', 'taxed' => '1'],
            ]],
        ]);

        $line = app(WhmcsInvoiceMapper::class)
            ->map($this->tenant, $pending, $this->customer, $this->invoiceType)['lines'][0];

        $this->assertSame(100.0, $line['net_price'], 'gross detected → net backed out');
        $this->assertSame(124.0, $line['gross_price']);
    }

    public function test_no_payload_breakdown_falls_back_to_toggle(): void
    {
        // No subtotal/tax fields → detection returns null → tenant toggle
        // (here: tax-exclusive) decides. Guards the fallback path.
        $this->tenant->forceFill(['whmcs_amount_includes_tax' => false])->save();

        $pending = $this->makePending([
            'invoiceid' => 1009, 'userid' => 5, 'date' => '2026-05-20', 'total' => '100.00',
            'items' => ['item' => [['description' => 'No breakdown', 'amount' => '100.00', 'taxed' => '1']]],
        ]);

        $line = app(WhmcsInvoiceMapper::class)
            ->map($this->tenant, $pending, $this->customer, $this->invoiceType)['lines'][0];

        $this->assertSame(100.0, $line['net_price'], 'toggle (net) used when payload has no breakdown');
        $this->assertSame(124.0, $line['gross_price']);
    }

    public function test_tax_exclusive_flag_applies_on_the_split_subset_path_too(): void
    {
        // G3 belt-and-suspenders: the flag must hold when map() is called with
        // an item subset (the multi-party split path), not just whole invoices.
        $this->tenant->forceFill(['whmcs_amount_includes_tax' => false])->save();

        $pending = $this->makePending([
            'invoiceid' => 1009,
            'userid' => 555,
            'items' => ['item' => [
                ['id' => 11, 'description' => 'Net line A', 'amount' => '100.00', 'taxed' => '1'],
                ['id' => 22, 'description' => 'Net line B', 'amount' => '200.00', 'taxed' => '1'],
            ]],
        ]);

        $lines = app(WhmcsInvoiceMapper::class)
            ->map($this->tenant, $pending, $this->customer, $this->invoiceType, [11])['lines'];

        $this->assertCount(1, $lines, 'only the selected item is mapped');
        $this->assertSame(100.0, $lines[0]['net_price'], 'subset line still treats amount as net');
        $this->assertSame(124.0, $lines[0]['gross_price']);
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
        $this->assertSame('Only one', $lines[0]['product_descr']);
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
        $this->assertSame('Real', $lines[0]['product_descr']);
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

    public function test_snapshot_includes_address2_and_vies_vat(): void
    {
        // Fix #7: WHMCS-filed invoices must capture the SAME legal-
        // snapshot field set as manually-issued invoices via InvoiceForm.
        // The mapper previously omitted address2 + vies_vat, producing
        // structurally different snapshots for the same customer
        // depending on which ingress path filed the invoice.
        $this->customer->update([
            'address2' => 'Building C, Floor 3',
            'vat_vies' => 'EL123456789',   // source col on Customer
        ]);

        $pending = $this->makePending([
            'invoiceid' => 1008,
            'items' => ['item' => [
                ['description' => 'X', 'amount' => '124.00', 'taxed' => '1'],
            ]],
        ]);

        $header = app(WhmcsInvoiceMapper::class)
            ->map($this->tenant, $pending, $this->customer, $this->invoiceType)['header'];

        $this->assertSame('Building C, Floor 3', $header['address2']);
        $this->assertSame('EL123456789', $header['vies_vat']);
    }

    public function test_line_rounding_mirrors_invoice_line_saving_hook(): void
    {
        // Fix #8: previously the mapper passed grossAmount through as
        // line_gross (€10.00) but the InvoiceLine::saving hook
        // recomputes gross from net (€10/1.24 = 8.06, then 8.06*1.24
        // = 9.99). Operator saw €10.00 in the preview, persisted
        // gross was €9.99 — silent €0.01-per-line drift.
        $pending = $this->makePending([
            'invoiceid' => 1009,
            'items' => ['item' => [
                ['description' => 'Odd amount', 'amount' => '10.00', 'taxed' => '1'],
            ]],
        ]);

        $result = app(WhmcsInvoiceMapper::class)
            ->map($this->tenant, $pending, $this->customer, $this->invoiceType);

        $line = $result['lines'][0];
        // Net is back-computed from gross via the same formula the
        // saving hook will use.
        $this->assertSame(8.06, $line['net_price']);
        // Gross is the re-derived value (matches what the saving hook
        // would persist), NOT the raw WHMCS amount. Preview now
        // tells the operator the truth even when WHMCS rounds
        // differently than ekdosi.
        $this->assertSame(9.99, $line['gross_price']);
    }

    public function test_zero_vat_lines_use_zero_rate_vat_category_when_one_exists(): void
    {
        // Second-pass regression: previously the mapper assigned the
        // tenant's DEFAULT 24% VatCategory to every line including
        // taxed=0 ones, producing vat_category_id/vat_percent mismatch
        // that misclassified tax-exempt amounts as standard-rate in
        // any consumer that groups by vat_category_id (Καρτέλα reports,
        // future PEPPOL, accountant CSV exports). Uses the 0%-rate
        // category seeded in setUp.
        $pending = $this->makePending([
            'invoiceid' => 1011,
            'items' => ['item' => [
                ['description' => 'Hosting', 'amount' => '124.00', 'taxed' => '1'],
                ['description' => 'Refund',  'amount' => '-20.00', 'taxed' => '0'],
            ]],
        ]);

        $lines = app(WhmcsInvoiceMapper::class)
            ->map($this->tenant, $pending, $this->customer, $this->invoiceType)['lines'];

        $this->assertSame($this->vat24->id, $lines[0]['vat_category_id'], 'taxed=1 line should use default 24% category');
        $this->assertSame($this->vat0->id, $lines[1]['vat_category_id'], 'taxed=0 line should use the 0%-rate category, NOT the default');
    }

    public function test_zero_vat_lines_throw_when_no_zero_rate_category_exists(): void
    {
        // Third-pass Tier 2 #5: the previous "fall back to default 24%"
        // behavior silently misclassified tax-exempt lines under the
        // standard rate in every consumer that groups by
        // vat_category_id (Καρτέλα reports, future PEPPOL, accountant
        // CSV exports). The misclassification damage is identical
        // regardless of submission mode, so the mapper now throws even
        // on Off-mode tenants. Operator action: configure a 0%-rate
        // VatCategory in Setup → VAT Categories before re-filing.
        $this->vat0->forceDelete();   // strip the setUp default

        $pending = $this->makePending([
            'invoiceid' => 1012,
            'items' => ['item' => [
                ['description' => 'Refund', 'amount' => '-20.00', 'taxed' => '0'],
            ]],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('0%-rate VatCategory');

        app(WhmcsInvoiceMapper::class)
            ->map($this->tenant, $pending, $this->customer, $this->invoiceType);
    }

    public function test_zero_vat_throw_does_not_fire_when_invoice_is_fully_taxed(): void
    {
        // Pre-check guard: the mapper must NOT consult the 0%-rate
        // VatCategory when every line is taxed=1. Tenants that file
        // only fully-taxed WHMCS invoices shouldn't need to configure
        // a 0%-rate row.
        $pending = $this->makePending([
            'invoiceid' => 1013,
            'items' => ['item' => [
                ['description' => 'Hosting', 'amount' => '124.00', 'taxed' => '1'],
            ]],
        ]);

        $lines = app(WhmcsInvoiceMapper::class)
            ->map($this->tenant, $pending, $this->customer, $this->invoiceType)['lines'];

        $this->assertCount(1, $lines);
        $this->assertSame($this->vat24->id, $lines[0]['vat_category_id']);
        $this->assertSame(24.0, $lines[0]['vat_percent']);
    }

    public function test_zero_vat_lines_are_surfaced_in_totals(): void
    {
        // Fix #2 plumbing: the mapper surfaces a list of 0%-VAT line
        // descriptions so the filer can refuse pre-persist and the
        // operator-facing message can name the problem lines.
        $pending = $this->makePending([
            'invoiceid' => 1010,
            'items' => ['item' => [
                ['description' => 'Hosting',       'amount' => '124.00', 'taxed' => '1'],
                ['description' => 'Refund credit', 'amount' => '-20.00', 'taxed' => '0'],
                ['description' => 'Goodwill',      'amount' => '0.00',   'taxed' => '0'],
            ]],
        ]);

        $totals = app(WhmcsInvoiceMapper::class)
            ->map($this->tenant, $pending, $this->customer, $this->invoiceType)['totals'];

        $this->assertContains('Refund credit', $totals['zero_vat_lines']);
        $this->assertContains('Goodwill', $totals['zero_vat_lines']);
        $this->assertNotContains('Hosting', $totals['zero_vat_lines']);
    }

    public function test_carries_whmcs_source_metadata(): void
    {
        $pending = $this->makePending([
            'invoiceid' => 12345,
            'userid' => 777,
            'date' => '2026-05-20',
            'total' => '1240.00',
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
