<?php

namespace Tests\Feature;

use App\Filament\Widgets\MyDataPictureStats;
use App\Models\Company;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\User;
use App\Services\Dashboard\VatPeriodReport;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * E6 — ΦΠΑ εκροών − εισροών report. Verifies the output (invoices) and input
 * (expenses) aggregation, the net = output − input, period scoping, and that
 * AADE-cancelled expenses are excluded from input VAT.
 */
class VatPeriodReportTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private InvoiceType $type;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Company::create([
            'name' => 'VAT test',
            'slug' => 'vat-'.uniqid(),
            'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata',
            'mydata_mode' => 'sandbox',
            'afm' => '801280908',
        ]);

        $this->type = InvoiceType::create([
            'company_id' => $this->tenant->id,
            'code' => 'TPY',
            'name' => 'ΤΠΥ',
            'invcount' => 0,
        ]);
    }

    private function invoice(string $issuedAt, float $net, float $gross, ?string $state = 'VALID'): Invoice
    {
        $inv = Invoice::create([
            'company_id' => $this->tenant->id,
            'invoice_type_id' => $this->type->id,
            'invcode' => 'TPY'.uniqid(),
            'code' => random_int(1, 999999),
            'issued_at' => $issuedAt,
            'net_total' => $net,
            'gross_total' => $gross,
            'local_status' => 'active',
        ]);

        // mydata_state is a non-fillable mirror column (written only via
        // forceFill by the submitter) — set it the same way here.
        if ($state !== null) {
            $inv->forceFill(['mydata_state' => $state])->save();
        }

        return $inv;
    }

    private function expense(string $issueDate, float $net, float $vat, float $gross, ?string $state = 'VALID', ?string $invoiceType = null): Expense
    {
        return Expense::create([
            'company_id' => $this->tenant->id,
            'mydata_mark' => (string) random_int(1, PHP_INT_MAX),
            'issue_date' => $issueDate,
            'invoice_type' => $invoiceType,
            'net_total' => $net,
            'vat_total' => $vat,
            'gross_total' => $gross,
            'mydata_state' => $state,
            'source' => 'sync',
        ]);
    }

    public function test_net_vat_is_output_minus_input(): void
    {
        // Output: two invoices in Jan 2026 → net 1000, gross 1240 → VAT 240.
        $this->invoice('2026-01-10 10:00:00', 600, 744);
        $this->invoice('2026-01-20 10:00:00', 400, 496);

        // Input: expenses in Jan → VAT 100 + 24.
        $this->expense('2026-01-12', 500, 100, 600);
        $this->expense('2026-01-15', 120, 24, 144);

        $summary = (new VatPeriodReport($this->tenant))->forPeriod(
            Carbon::parse('2026-01-01')->startOfDay(),
            Carbon::parse('2026-01-31')->endOfDay(),
        );

        $this->assertSame(1240.00, $summary->outputGross);
        $this->assertSame(240.00, $summary->outputVat);
        $this->assertSame(124.00, $summary->inputVat);
        $this->assertSame(744.00, $summary->inputGross);

        // net = 240 − 124 = 116 → προς απόδοση.
        $this->assertSame(116.00, $summary->netVat());
        $this->assertTrue($summary->isPayable());
    }

    public function test_credit_note_reduces_output_vat(): void
    {
        // MON-2: a €1240-gross sale fully credited by a €1240 credit note in the
        // same period → output VAT nets to €0. Before the fix income() only
        // EXCLUDED the credit note, so it over-declared €240 output VAT.
        $sale = $this->invoice('2026-02-10 10:00:00', 1000, 1240);
        $credit = $this->invoice('2026-02-15 10:00:00', 1000, 1240);
        $credit->forceFill(['credited_invoice_id' => $sale->id])->save();

        $summary = (new VatPeriodReport($this->tenant))->forPeriod(
            Carbon::parse('2026-02-01')->startOfDay(),
            Carbon::parse('2026-02-28')->endOfDay(),
        );

        $this->assertSame(0.00, $summary->outputVat);
        $this->assertSame(0.00, $summary->outputNet);
        $this->assertSame(0.00, $summary->outputGross);
        $this->assertSame(2, $summary->outputCount);   // both are output documents
    }

    public function test_partial_credit_note_partially_reduces_output_vat(): void
    {
        // €1240 sale − €620 partial credit → output VAT 240 − 120 = 120.
        $sale = $this->invoice('2026-04-10 10:00:00', 1000, 1240);
        $credit = $this->invoice('2026-04-15 10:00:00', 500, 620);
        $credit->forceFill(['credited_invoice_id' => $sale->id])->save();

        $summary = (new VatPeriodReport($this->tenant))->forPeriod(
            Carbon::parse('2026-04-01')->startOfDay(),
            Carbon::parse('2026-04-30')->endOfDay(),
        );

        $this->assertSame(120.00, $summary->outputVat);
        $this->assertSame(620.00, $summary->outputGross);
    }

    public function test_cancelled_credit_note_does_not_reduce_output_vat(): void
    {
        // A CANCELLED credit note is not live → must NOT reduce output VAT.
        $sale = $this->invoice('2026-05-10 10:00:00', 1000, 1240);
        $credit = $this->invoice('2026-05-15 10:00:00', 1000, 1240, state: 'CANCELLED');
        $credit->forceFill(['credited_invoice_id' => $sale->id])->save();

        $summary = (new VatPeriodReport($this->tenant))->forPeriod(
            Carbon::parse('2026-05-01')->startOfDay(),
            Carbon::parse('2026-05-31')->endOfDay(),
        );

        $this->assertSame(240.00, $summary->outputVat);
    }

    public function test_credit_balance_when_input_exceeds_output(): void
    {
        $this->invoice('2026-02-10 10:00:00', 100, 124);      // output VAT 24
        $this->expense('2026-02-11', 1000, 240, 1240);        // input VAT 240

        $summary = (new VatPeriodReport($this->tenant))->forPeriod(
            Carbon::parse('2026-02-01')->startOfDay(),
            Carbon::parse('2026-02-28')->endOfDay(),
        );

        $this->assertSame(-216.00, $summary->netVat());
        $this->assertFalse($summary->isPayable());
    }

    public function test_cancelled_expense_excluded_from_input_and_period_scoped(): void
    {
        $this->invoice('2026-03-10 10:00:00', 100, 124);      // output VAT 24

        $this->expense('2026-03-12', 500, 120, 620);                       // counts
        $this->expense('2026-03-13', 999, 999, 1998, state: 'CANCELLED');  // excluded
        $this->expense('2026-02-28', 777, 777, 1554);                      // out of period

        $summary = (new VatPeriodReport($this->tenant))->forPeriod(
            Carbon::parse('2026-03-01')->startOfDay(),
            Carbon::parse('2026-03-31')->endOfDay(),
        );

        $this->assertSame(120.00, $summary->inputVat, 'only the live in-period expense counts');
        $this->assertSame(1, $summary->inputCount);
        $this->assertSame(-96.00, $summary->netVat());        // 24 − 120
    }

    public function test_expense_credit_note_reduces_input_vat(): void
    {
        $this->invoice('2026-04-10 10:00:00', 100, 124);                        // output VAT 24

        $this->expense('2026-04-12', 1000, 240, 1240, invoiceType: '14.3');     // input VAT +240
        $this->expense('2026-04-15', 100, 24, 124, invoiceType: '14.31');       // πιστωτικό → −24

        $summary = (new VatPeriodReport($this->tenant))->forPeriod(
            Carbon::parse('2026-04-01')->startOfDay(),
            Carbon::parse('2026-04-30')->endOfDay(),
        );

        // Input nets the credit note out: 240 − 24 = 216 (not 264).
        $this->assertSame(216.00, $summary->inputVat);
        $this->assertSame(900.00, $summary->inputNet);
        $this->assertSame(-192.00, $summary->netVat());        // 24 − 216
    }

    public function test_months_of_quarter_returns_three(): void
    {
        $months = (new VatPeriodReport($this->tenant))->monthsOfQuarter(Carbon::parse('2026-02-15'));

        $this->assertCount(3, $months);
        $this->assertSame('01/2026', $months[0]->label);
        $this->assertSame('03/2026', $months[2]->label);
    }

    public function test_widget_hidden_for_non_mydata_tenant(): void
    {
        // Filament's TenantSet event requires an authenticated user.
        $user = User::create([
            'name' => 'Op', 'email' => 'op-'.uniqid().'@test.local', 'password' => bcrypt('x'),
        ]);
        $this->actingAs($user);

        Filament::setTenant($this->tenant);
        $this->assertTrue(MyDataPictureStats::canView());

        $ee = Company::create([
            'name' => 'EE', 'slug' => 'ee-'.uniqid(),
            'country_code' => 'EE', 'einvoice_provider' => 'ee-peppol', 'mydata_mode' => 'off',
        ]);
        Filament::setTenant($ee);
        $this->assertFalse(MyDataPictureStats::canView());
    }
}
