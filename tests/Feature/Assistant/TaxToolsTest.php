<?php

namespace Tests\Feature\Assistant;

use App\Models\Company;
use App\Models\Expense;
use App\Services\Accounting\E3YearTotals;
use App\Services\Accounting\IncomeTaxEstimate;
use App\Services\Assistant\Tools\DataFreshnessTool;
use App\Services\Assistant\Tools\E3SnapshotTool;
use App\Services\Assistant\Tools\ExpenseListTool;
use App\Services\Assistant\Tools\TaxOverviewTool;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The «Φορολογικά» read tools (tax_overview / e3_snapshot / expense_list /
 * data_freshness) — same numbers as the page's services, tenant-scoped, GR-only.
 * Dates sit mid-month (sqlite DATE-cast caveat, see TaxOverviewTest).
 */
class TaxToolsTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-25 10:00:00');

        $this->tenant = Company::create([
            'name' => 'Tools OE', 'slug' => 'tt-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'sandbox', 'afm' => '801280908',
            'mydata_aade_id_sandbox' => 'TESTUSER', 'mydata_subscription_key_sandbox' => 'TESTKEY',
        ]);

        // Local: supplier invoices + a 17.1 payroll (one E3_581_001 line = gross + employer
        // contributions) for Jan–Jul; nothing for Aug. Ε3: the same payroll SPLIT, plus a
        // June payroll that never reached us as a document.
        $this->expense('sync', null, '1.1', '2026-03-10', 2000, 480);
        $this->expense('sync', null, '1.1', '2026-04-10', 300, 72, afm: '099999999', name: 'ΑΛΦΑ ΑΕ');
        $this->expense('sync', null, '5.1', '2026-04-20', 100, 24, afm: '099999999', name: 'ΑΛΦΑ ΑΕ');   // credit
        $this->expense('self_declared', 'intracommunity', '14.3', '2026-05-12', 1000, 240);
        foreach (range(1, 7) as $m) {
            $this->payroll(sprintf('2026-%02d-15', $m), 3000);
        }
        E3YearTotals::refresh($this->tenant, 2026, $this->e3([
            ...array_merge(...array_map(fn ($m) => [
                [sprintf('2026-%02d-15', $m), 'category2_6', 'E3_581_001', 2400],
                [sprintf('2026-%02d-15', $m), 'category2_6', 'E3_581_002', 600],
            ], range(1, 7))),
            ['2026-06-30', 'category2_6', 'E3_581_001', 3000],     // June: a second payroll only the Ε3 has
            ['2026-03-10', 'category2_4', 'E3_585_016', 2000],
        ]));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_tax_overview_returns_what_the_page_shows(): void
    {
        $out = (new TaxOverviewTool)->run($this->tenant, []);
        $e = (new IncomeTaxEstimate($this->tenant))->forYear(2026);

        $this->assertSame(2026, $out['year']);
        $this->assertSame('blend', $out['source']);
        $this->assertEqualsWithDelta($e['expense_total'], $out['estimate']['expense_total'], 0.001);
        $this->assertEqualsWithDelta($e['headline_payable'], $out['estimate']['headline_payable'], 0.001);
        $this->assertSame('missing', $out['estimate']['prior_prepayment_source']);

        $march = collect($out['monthly']['months'])->firstWhere('month', 3);
        $this->assertSame('Μάρτιος', $march['label']);
        $this->assertEqualsWithDelta(600.0, $march['contributions'], 0.001);     // the Ε3's official split
        $this->assertContains('contributions', $march['e3_columns']);
        $this->assertNotContains('payroll', $march['e3_columns']);
        $this->assertCount(4, $out['vat']['quarters']);
        // Q2 input VAT: 72 − 24 (credit) — the 14.3's 240 is reverse charge, not deductible.
        $this->assertEqualsWithDelta(48.0, $out['vat']['quarters'][1]['input_vat'], 0.001);
    }

    public function test_e3_snapshot_groups_per_month_and_detail_rows(): void
    {
        $out = (new E3SnapshotTool)->run($this->tenant, ['detail' => true]);

        $this->assertTrue($out['found']);
        $this->assertEqualsWithDelta(7 * 2400 + 3000, $out['groups']['payroll'], 0.001);
        $this->assertEqualsWithDelta(7 * 600, $out['groups']['contributions'], 0.001);
        $june = collect($out['months'])->firstWhere('month', 6);
        $this->assertEqualsWithDelta(5400.0, $june['payroll'], 0.001);
        $this->assertNotEmpty($june['rows']);
        $this->assertSame('E3_581_001', $june['rows'][0]['type']);

        $this->assertFalse((new E3SnapshotTool)->run($this->tenant, ['year' => 2025])['found']);
    }

    public function test_expense_list_filters_totals_and_lines(): void
    {
        $payroll = (new ExpenseListTool)->run($this->tenant, ['category' => 'payroll', 'with_lines' => true]);
        $this->assertSame(7, $payroll['totals']['count']);
        $this->assertEqualsWithDelta(21000.0, $payroll['totals']['net'], 0.001);
        $this->assertSame('E3_581_001', $payroll['rows'][0]['lines'][0]['classification_type']);
        $this->assertStringContainsString('/expenses/', $payroll['rows'][0]['url']);

        $supplier = (new ExpenseListTool)->run($this->tenant, ['supplier' => 'ΑΛΦΑ']);
        $this->assertSame(2, $supplier['totals']['count']);
        $this->assertEqualsWithDelta(200.0, $supplier['totals']['net'], 0.001);   // 300 − 100 credit

        $intra = (new ExpenseListTool)->run($this->tenant, ['invoice_type' => '14']);
        $this->assertSame(1, $intra['totals']['count']);

        $this->assertArrayHasKey('error', (new ExpenseListTool)->run($this->tenant, ['category' => 'nope']));
    }

    public function test_data_freshness_finds_the_missing_payroll_months_and_the_e3_gaps(): void
    {
        $out = (new DataFreshnessTool)->run($this->tenant, []);

        // 25/09: August is over and has no payroll on either side; September is still running.
        $this->assertSame(['Αύγουστος'], $out['payroll_missing_months']);

        $june = collect($out['local_vs_e3'])->first(fn ($d) => $d['month'] === 6 && $d['group'] === 'personnel');
        $this->assertEqualsWithDelta(3000.0, $june['diff'], 0.001);             // the payroll only the Ε3 has
        $this->assertStringContainsString('Το Ε3 έχει περισσότερα', $june['meaning']);
        $this->assertNull(collect($out['local_vs_e3'])->first(fn ($d) => $d['month'] === 1));   // Jan agrees

        $payroll = collect($out['expense_categories'])->firstWhere('category', 'payroll');
        $this->assertSame('2026-07-15', $payroll['last_date']);
        $this->assertStringContainsString('E3_585_007', implode(' ', $out['notes']));
    }

    public function test_non_greek_tenants_and_bad_years_get_a_structured_error(): void
    {
        $ee = Company::create(['name' => 'EE OÜ', 'slug' => 'ee-'.uniqid(), 'country_code' => 'EE', 'einvoice_provider' => 'none']);

        foreach ([new TaxOverviewTool, new E3SnapshotTool, new DataFreshnessTool] as $tool) {
            $this->assertArrayHasKey('error', $tool->run($ee, []), $tool->name());
            $this->assertArrayHasKey('error', $tool->run($this->tenant, ['year' => 2031]), $tool->name());
        }
    }

    private function expense(string $source, ?string $category, string $type, string $date, float $net, float $vat, string $afm = '123456789', string $name = 'Χ'): Expense
    {
        return Expense::create([
            'company_id' => $this->tenant->id, 'supplier_afm' => $afm, 'supplier_name' => $name,
            'source' => $source, 'category' => $category, 'invoice_type' => $type, 'issue_date' => $date,
            'net_total' => $net, 'vat_total' => $vat, 'gross_total' => $net + $vat, 'currency' => 'EUR',
            'mydata_mark' => (string) random_int(1, PHP_INT_MAX),
        ]);
    }

    private function payroll(string $date, float $net): void
    {
        $e = $this->expense('self_declared', 'payroll', '17.1', $date, $net, 0);
        $e->lines()->create([
            'company_id' => $this->tenant->id, 'line_number' => 1, 'net_value' => $net, 'vat_amount' => 0,
            'classification_type' => 'E3_581_001', 'classification_category' => 'category2_6',
        ]);
    }

    /** @param list<array{0:string,1:string,2:string,3:float}> $rows (issueDate, category, type, value) */
    private function e3(array $rows): MockHandler
    {
        $items = '';
        foreach ($rows as $i => [$date, $cat, $type, $value]) {
            $items .= sprintf(
                '<E3Info><V_Afm>801280908</V_Afm><V_Mark>4000000000%05d</V_Mark><IssueDate>%sT00:00:00</IssueDate>'
                .'<V_Class_Category>%s</V_Class_Category><V_Class_Type>%s</V_Class_Type><V_Class_Value>%.2f</V_Class_Value></E3Info>',
                $i + 1, $date, $cat, $type, $value,
            );
        }

        return new MockHandler([new Response(200, [], '<?xml version="1.0" encoding="utf-8"?><RequestedE3Info xmlns="http://www.aade.gr/myDATA/invoice/v1.0">'.$items.'</RequestedE3Info>')]);
    }
}
