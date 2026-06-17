<?php

namespace Tests\Feature\CustomerLedger;

use App\Filament\Resources\Customers\Pages\CustomerLedger;
use App\Filament\Resources\Customers\Widgets\CustomerLedgerAging;
use App\Filament\Resources\Customers\Widgets\CustomerLedgerBalanceChart;
use App\Filament\Resources\Customers\Widgets\CustomerLedgerRevenueChart;
use App\Filament\Resources\Customers\Widgets\CustomerLedgerStats;
use App\Mail\CustomerStatementMail;
use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\CustomerLedger\CustomerStatementCsv;
use App\Services\CustomerLedger\CustomerStatementPdfRenderer;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The "polish" pass: the Καρτέλα page now renders Filament widgets + a
 * records()-backed movements table, plus PDF/CSV export and email-the-
 * statement actions. These tests stand in for visual verification —
 * they assert the page + widgets render without runtime errors and the
 * new actions behave.
 */
class CustomerLedgerPagePolishTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private Customer $customer;

    private PaymentMethod $credit;

    private InvoiceType $invType;

    private static int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Company::create([
            'name' => 'Polish Test',
            'slug' => 'polish-'.uniqid(),
            'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata',
            'mydata_mode' => 'off',
        ]);

        $this->credit = PaymentMethod::create([
            'company_id' => $this->tenant->id,
            'name' => 'Credit 30d',
            'due_days' => 30,
            'is_active' => true,
        ]);

        $this->invType = InvoiceType::create([
            'company_id' => $this->tenant->id,
            'name' => 'ΤΠΥ',
            'code' => 'TPY',
            'invcount' => 0,
            'payment_method_id' => $this->credit->id,
        ]);

        $this->customer = Customer::create([
            'company_id' => $this->tenant->id,
            'name' => 'Πελάτης Πολυτελείας',
            'afm' => '123456789',
            'email' => 'pelatis@example.test',
        ]);

        $user = User::create([
            'name' => 'Op',
            'email' => 'op-'.uniqid().'@example.test',
            'password' => bcrypt('x'),
        ]);

        Gate::before(fn () => true);
        $this->actingAs($user);
        Filament::setTenant($this->tenant);
    }

    private function makeInvoice(string $issuedAt, float $gross): Invoice
    {
        self::$seq++;

        return Invoice::create([
            'company_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
            'invoice_type_id' => $this->invType->id,
            'payment_method_id' => $this->credit->id,
            'invcode' => 'TPY'.self::$seq,
            'code' => self::$seq,
            'issued_at' => $issuedAt,
            'gross_total' => $gross,
            'net_total' => round($gross / 1.24, 2),
            'mydata_state' => 'VALID',
        ]);
    }

    public function test_page_renders_with_movements_table_and_widgets(): void
    {
        $this->makeInvoice('2025-06-01', 124.0);
        $this->makeInvoice(now()->subDays(5)->toDateString(), 248.0);
        Payment::create([
            'company_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
            'pay_date' => now()->subDays(2)->toDateString(),
            'amount' => 100.0,
        ]);

        Livewire::test(CustomerLedger::class, ['record' => $this->customer->id])
            ->assertOk()
            ->assertSee('Καρτέλα κινήσεων')
            ->assertSee('TPY'); // an invoice reference appears in the table
    }

    public function test_period_summary_is_null_until_a_year_is_picked_then_totals(): void
    {
        $this->makeInvoice('2025-06-01', 124.0); // net = 100
        $this->makeInvoice('2025-09-01', 248.0); // net = 200

        $component = Livewire::test(CustomerLedger::class, ['record' => $this->customer->id])
            ->assertOk();

        // «Όλα τα έτη» → no period card.
        $this->assertNull($component->instance()->getPeriodSummary());

        // Pick 2025 in the table year filter → period totals appear and match
        // the cached per-year breakdown (2 invoices, net 300, gross 372).
        $component->set('tableFilters.year.value', '2025');
        $summary = $component->instance()->getPeriodSummary();

        $this->assertNotNull($summary);
        $this->assertSame(2025, $summary['year']);
        $this->assertSame(2, $summary['invoice_count']);
        $this->assertEqualsWithDelta(300.0, $summary['net'], 0.01);
        $this->assertEqualsWithDelta(372.0, $summary['gross'], 0.01);
    }

    public function test_empty_customer_shows_empty_state_and_still_renders(): void
    {
        Livewire::test(CustomerLedger::class, ['record' => $this->customer->id])
            ->assertOk()
            ->assertSee('δεν έχει κινήσεις');
    }

    public function test_stats_widget_renders_with_props_and_yoy(): void
    {
        $cur = now()->year;
        $prev = $cur - 1;

        Livewire::test(CustomerLedgerStats::class, [
            'ledgerStats' => [
                'ytd_net' => 100.0, 'ytd_gross' => 124.0, 'ytd_paid' => 50.0,
                'balance' => 74.0, 'oldest_unpaid_days' => 12,
                'last_activity_at' => now()->toIso8601String(),
                'total_invoices_lifetime' => 3,
            ],
            'ledgerYearly' => [
                ['year' => $cur, 'net' => 100.0, 'gross' => 124.0, 'paid' => 50.0, 'year_end_balance' => 74.0],
                ['year' => $prev, 'net' => 80.0, 'gross' => 99.0, 'paid' => 80.0, 'year_end_balance' => 0.0],
            ],
        ])
            ->assertOk()
            ->assertSee('Υπόλοιπο')
            // YoY: net 100 vs 80 last year → +25% trend referencing prev year.
            ->assertSee('vs '.$prev);
    }

    public function test_revenue_chart_widget_renders(): void
    {
        $cur = now()->year;

        Livewire::test(CustomerLedgerRevenueChart::class, [
            'ledgerYearly' => [
                ['year' => $cur, 'net' => 100.0, 'gross' => 124.0, 'paid' => 50.0, 'year_end_balance' => 74.0],
                ['year' => $cur - 1, 'net' => 80.0, 'gross' => 99.0, 'paid' => 80.0, 'year_end_balance' => 0.0],
            ],
        ])->assertOk();
    }

    public function test_aging_widget_collapses_when_settled(): void
    {
        Livewire::test(CustomerLedgerAging::class, [
            'ledgerAging' => ['bucket_0_30' => 0, 'bucket_31_60' => 0, 'bucket_61_90' => 0, 'bucket_90_plus' => 0],
            'ledgerStats' => ['balance' => 0.0],
        ])->assertOk()->assertSee('Καμία');
    }

    public function test_balance_chart_widget_renders(): void
    {
        Livewire::test(CustomerLedgerBalanceChart::class, [
            'ledgerYearly' => [
                ['year' => 2025, 'year_end_balance' => 74.0],
                ['year' => 2024, 'year_end_balance' => 124.0],
            ],
        ])->assertOk();
    }

    public function test_pdf_renderer_produces_pdf_bytes(): void
    {
        $this->makeInvoice('2025-06-01', 124.0);

        $bytes = app(CustomerStatementPdfRenderer::class)->render($this->customer);

        $this->assertNotEmpty($bytes);
        $this->assertSame('%PDF', substr($bytes, 0, 4));
    }

    public function test_csv_export_contains_header_and_movement(): void
    {
        $this->makeInvoice('2025-06-01', 124.0);

        $csv = app(CustomerStatementCsv::class)->build($this->customer);

        $this->assertStringContainsString('Ημερομηνία', $csv);
        $this->assertStringContainsString('TPY', $csv);
        // el-GR Excel locale: comma decimal, no thousands separator.
        $this->assertStringContainsString('124,00', $csv);
        $this->assertStringNotContainsString('124.00', $csv);
    }

    public function test_export_pdf_action_returns_download(): void
    {
        $this->makeInvoice('2025-06-01', 124.0);

        Livewire::test(CustomerLedger::class, ['record' => $this->customer->id])
            ->callAction('export_pdf')
            ->assertFileDownloaded();
    }

    public function test_renders_whmcs_panel_section_collapsed_without_calling_api(): void
    {
        // A WHMCS-linked customer on an integrated tenant: the collapsible
        // panel section must render (and NOT hit the WHMCS API, since the
        // panel starts collapsed / unloaded).
        $this->tenant->forceFill([
            'whmcs_api_url' => 'https://billing.example.test/includes/api.php',
            'whmcs_api_identifier' => 'id',
            'whmcs_api_secret' => 'secret',
        ])->save();
        $this->customer->forceFill(['whmcs_client_id' => 42])->save();
        $this->makeInvoice('2025-06-01', 124.0);

        Livewire::test(CustomerLedger::class, ['record' => $this->customer->id])
            ->assertOk()
            ->assertSee('Τιμολόγια από WHMCS')
            ->assertSet('showWhmcsPanel', false);
    }

    public function test_email_statement_action_sends_mail(): void
    {
        Mail::fake();
        $this->makeInvoice('2025-06-01', 124.0);

        Livewire::test(CustomerLedger::class, ['record' => $this->customer->id])
            ->callAction('email_statement', data: [
                'recipients' => [],
                'extra_recipients' => 'accountant@example.test',
                'subject' => null,
                'message' => 'Ορίστε η καρτέλα σας.',
            ])
            ->assertHasNoActionErrors();

        Mail::assertSent(CustomerStatementMail::class, function (CustomerStatementMail $mail) {
            return $mail->hasTo('accountant@example.test');
        });
    }

    public function test_email_statement_defaults_to_customer_and_primary_contact(): void
    {
        Mail::fake();
        $this->makeInvoice('2025-06-01', 124.0);

        // A primary λογιστήριο contact with its own email is pre-checked
        // alongside the customer's email.
        CustomerContact::create([
            'company_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
            'name' => 'Μαρία',
            'role' => 'Λογιστήριο',
            'email' => 'logistirio@example.test',
            'is_primary' => true,
        ]);
        // A secondary contact (not primary) is offered but NOT pre-checked.
        CustomerContact::create([
            'company_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
            'name' => 'Τεχνικός',
            'role' => 'Support',
            'email' => 'tech@example.test',
        ]);

        Livewire::test(CustomerLedger::class, ['record' => $this->customer->id])
            ->callAction('email_statement', data: [
                // Mirror the pre-checked default (customer + primary contact).
                'recipients' => ['pelatis@example.test', 'logistirio@example.test'],
                'extra_recipients' => null,
                'subject' => null,
                'message' => null,
            ])
            ->assertHasNoActionErrors();

        Mail::assertSent(CustomerStatementMail::class, function (CustomerStatementMail $mail) {
            return $mail->hasTo('pelatis@example.test')
                && $mail->hasTo('logistirio@example.test')
                && ! $mail->hasTo('tech@example.test');
        });
    }

    public function test_email_statement_merges_picked_and_extra_recipients_and_dedupes(): void
    {
        Mail::fake();
        $this->makeInvoice('2025-06-01', 124.0);

        Livewire::test(CustomerLedger::class, ['record' => $this->customer->id])
            ->callAction('email_statement', data: [
                // Picked + a duplicate (different casing) + an extra + a bad one.
                'recipients' => ['pelatis@example.test'],
                'extra_recipients' => 'PELATIS@example.test, extra@example.test, not-an-email',
                'subject' => null,
                'message' => null,
            ])
            ->assertHasNoActionErrors();

        Mail::assertSent(CustomerStatementMail::class, function (CustomerStatementMail $mail) {
            // Deduped to the first casing; extra included; the invalid one dropped.
            return $mail->hasTo('pelatis@example.test')
                && $mail->hasTo('extra@example.test')
                && count($mail->to) === 2;
        });
    }

    public function test_email_statement_requires_a_valid_recipient(): void
    {
        Mail::fake();
        $this->makeInvoice('2025-06-01', 124.0);

        Livewire::test(CustomerLedger::class, ['record' => $this->customer->id])
            ->callAction('email_statement', data: [
                'recipients' => [],
                'extra_recipients' => 'garbage',
                'subject' => null,
                'message' => null,
            ])
            ->assertHasNoActionErrors();

        Mail::assertNothingSent();
    }
}
