<?php

namespace Tests\Feature\Pos;

use App\Actions\CreatePosReturn;
use App\Actions\CreatePosSale;
use App\Actions\PosExchangeIncomplete;
use App\Actions\PosSaleNotIssued;
use App\Filament\Pages\PointOfSale;
use App\Http\Controllers\PosSessionReportController;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\PaymentMethod;
use App\Models\PosCashMovement;
use App\Models\PosSession;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Models\VatCategory;
use App\Services\Pos\PosIssuer;
use App\Services\Pos\TillSessions;
use App\Services\RecomputeInvoiceTotals;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * «Ταμείο ημέρας» (POS PR 2b): open with a float, cash in/out, close with a count.
 * Every till document carries its session; the closing report is frozen.
 */
class PosTillSessionTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private VatCategory $vat;

    private ProductCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Company::create(['name' => 'Κατάστημα', 'slug' => 'till-'.uniqid(), 'country_code' => 'GR', 'einvoice_provider' => 'none']);
        $this->vat = VatCategory::create(['company_id' => $this->tenant->id, 'description' => '24%', 'rate' => 24, 'is_default' => true]);
        $this->category = ProductCategory::create(['company_id' => $this->tenant->id, 'description_short' => 'Ένδυση', 'markup' => 0]);
        $receipt = InvoiceType::create(['company_id' => $this->tenant->id, 'code' => 'ΑΛΠ', 'name' => 'Απόδειξη Λιανικής Πώλησης', 'invcount' => 1, 'mydata_type' => '11.1']);
        $credit = InvoiceType::create(['company_id' => $this->tenant->id, 'code' => 'ΠΙΛ', 'name' => 'Πιστωτικό Λιανικής', 'invcount' => 1, 'mydata_type' => '11.4', 'is_credit' => true]);
        $cash = PaymentMethod::create(['company_id' => $this->tenant->id, 'description' => 'Μετρητά', 'due_days' => 0, 'mydata_payment_type' => 3]);
        $this->tenant->update(['pos_enabled' => true, 'pos_invoice_type_id' => $receipt->id, 'pos_payment_method_id' => $cash->id, 'pos_credit_type_id' => $credit->id]);
    }

    public function test_a_day_open_sell_exchange_cash_moves_and_close_with_the_count(): void
    {
        $tee = $this->product('Μπλουζάκι', 24.11, ['price_wvat' => 29.90]);
        $cap = $this->product('Καπέλο', 8.06, ['price_wvat' => 10.00]);
        $this->operator(open: false);

        $page = Livewire::test(PointOfSale::class)
            ->assertSee('Το ταμείο είναι κλειστό')
            ->set('openingFloat', '50')->call('openTill')
            ->assertSee('Ταμείο #');
        $session = app(TillSessions::class)->current($this->tenant);
        $this->assertSame(50.0, (float) $session->opening_float);

        // A sale of 39,90, then an exchange: the 29,90 back, a 10,00 out.
        $page->call('choose', $tee->id)->call('choose', $cap->id)->call('checkout');
        $sale = Invoice::where('pos_session_id', $session->id)->sole();
        $this->assertSame(39.90, (float) $sale->payableTotal());
        $page->call('scanCode', $sale->receiptCode())
            ->call('returnMore', $sale->lines()->where('product_id', $tee->id)->value('id'))
            ->call('choose', $cap->id)
            ->call('checkout');
        $this->assertSame(3, Invoice::where('pos_session_id', $session->id)->count(), 'the credit note AND the new sale belong to the session');

        // Cash in / out.
        $page->call('startCash', 'in')->set('cashAmount', '20')->set('cashReason', 'ψιλά')->call('saveCash')
            ->call('startCash', 'out')->set('cashAmount', '15')->set('cashReason', 'κούριερ')->call('saveCash');
        $this->assertSame(2, PosCashMovement::where('pos_session_id', $session->id)->count());

        $report = app(TillSessions::class)->report($session->fresh());
        $this->assertSame(2, $report['sales_count']);
        $this->assertSame(49.90, $report['sales_total']);
        $this->assertSame(1, $report['refunds_count']);
        $this->assertSame(29.90, $report['refunds_total']);
        $this->assertSame(20.00, $report['net_total']);
        $this->assertSame(75.00, $report['expected_cash'], '50 + 49,90 − 29,90 + 20 − 15');
        $this->assertSame('Μετρητά', $report['by_method'][0]['method']);
        $this->assertSame(20.00, $report['vat'][0]['gross']);

        // Close with a count 0,50 short → the report prints.
        $page->call('startClose')->assertSee('Αναμενόμενα μετρητά')
            ->set('countedCash', '74,50')->call('closeTill')
            ->assertDispatched('pos-print', fn ($name, $params) => str_contains($params['url'], '/pos/session/'.$session->id.'/report'))
            ->assertSee('Το ταμείο είναι κλειστό');
        $closed = $session->fresh();
        $this->assertNotNull($closed->closed_at);
        $this->assertSame(75.00, (float) $closed->expected_cash);
        $this->assertSame(74.50, (float) $closed->counted_cash);

        // A closed day is frozen: cancelling one of its receipts later does not rewrite it.
        $sale->forceFill(['local_status' => 'cancelled'])->save();
        $this->assertSame(75.00, app(TillSessions::class)->report($closed->fresh())['expected_cash'], 'frozen — and still a float after the JSON round-trip');

        $html = $this->get(PosSessionReportController::signedUrl($closed->id))->assertOk()->getContent();
        $text = preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(str_replace('<', ' <', $html))));
        $this->assertStringContainsString('ΚΛΕΙΣΙΜΟ ΤΑΜΕΙΟΥ', $text);
        $this->assertStringContainsString('Αναμενόμενα 75,00 €', $text);
        $this->assertStringContainsString('Διαφορά −0,50 €', $text);
        $this->assertStringContainsString('κούριερ', $text);
    }

    public function test_unissued_documents_are_pending_cash_and_the_bag_fee_has_its_own_line(): void
    {
        $tills = app(TillSessions::class);
        $session = $tills->open($this->tenant, null, 0);
        $bag = $this->product('Σακούλα', 0.01, ['price_wvat' => 0.01, 'mydata_tax_type' => 2, 'mydata_tax_per_unit' => 0.07]);
        $tee = $this->product('Μπλουζάκι', 8.06, ['price_wvat' => 10.00]);
        app(CreatePosSale::class)($this->tenant, [['product_id' => $tee->id, 'qty' => 1], ['product_id' => $bag->id, 'qty' => 2]], $session);

        $report = $tills->report($session);
        $this->assertSame(10.16, $report['net_total'], '10,00 + 2 × (0,01 + 0,07)');
        $this->assertSame(0.14, $report['levies'], 'the bag fee has its own line');
        $this->assertSame(10.16, round(array_sum(array_column($report['vat'], 'gross')) + $report['levies'], 2), 'VAT table + fees = turnover');

        // A sale stuck as a DRAFT (being filed / AADE down / in doubt) never blocks the close:
        // its cash is in the drawer — listed as pending and counted.
        $draft = app(CreatePosSale::class)($this->tenant, [['product_id' => $tee->id, 'qty' => 1]], $session);
        $draft->forceFill(['local_status' => 'draft', 'invcode' => null])->saveQuietly();
        $report = $tills->report($session);
        $this->assertSame([['code' => '#'.$draft->id, 'amount' => 10.0]], $report['pending']);
        $this->assertSame(10.16, $report['net_total'], 'not a sale until issued');
        $this->assertSame(20.16, $report['expected_cash'], 'but its cash is in the drawer');

        $tills->close($session, null, 20.16);
        $this->assertFalse($session->fresh()->isOpen());
        $this->assertSame(20.16, $tills->report($session->fresh())['expected_cash']);
    }

    public function test_a_failed_exchange_sale_and_a_failed_return_never_count_as_drawer_cash(): void
    {
        $tills = app(TillSessions::class);
        $session = $tills->open($this->tenant, null, 0);
        $tee = $this->product('Μπλουζάκι', 8.06, ['price_wvat' => 10.00]);
        $sale = app(CreatePosSale::class)($this->tenant, [['product_id' => $tee->id, 'qty' => 1]], $session);

        // The exchange's NEW sale is rung as a draft, then fails to issue.
        $this->app->bind(CreatePosSale::class, fn ($app) => new class($app->make(RecomputeInvoiceTotals::class), $app->make(PosIssuer::class)) extends CreatePosSale
        {
            public function __invoke(Company $company, array $items, ?PosSession $session = null): Invoice
            {
                $draft = Invoice::create(['company_id' => $company->id, 'invoice_type_id' => $company->pos_invoice_type_id, 'issued_at' => now(),
                    'local_status' => 'draft', 'header_discount_percent' => 0, 'payment_method_id' => $company->pos_payment_method_id, 'country' => 'GR']);
                $draft->forceFill(['pos_session_id' => $session?->id, 'payable_total' => 10])->saveQuietly();

                throw new PosSaleNotIssued($draft->id, new RuntimeException('AADE down'));
            }
        });
        try {
            app(CreatePosReturn::class)($this->tenant, $sale, [$sale->lines()->first()->id => 1], [['product_id' => $tee->id, 'qty' => 1]], $session);
            $this->fail('expected an incomplete exchange');
        } catch (PosExchangeIncomplete $e) {
            $this->assertStringContainsString('μην την ξαναχτυπήσεις', $e->getMessage());
            $this->assertNull(Invoice::find($e->saleDraftId)->pos_session_id, 'no money was taken for it');
        }

        // A return credit DRAFT (not issued) is listed, never counted as cash paid out.
        $credit = Invoice::whereNotNull('credited_invoice_id')->sole();
        $credit->forceFill(['local_status' => 'draft'])->saveQuietly();
        $report = $tills->report($session);
        $this->assertSame(10.00, $report['expected_cash'], 'the sale\'s 10,00 — nothing refunded, nothing pending');
        $this->assertCount(1, $report['pending']);
    }

    public function test_a_count_is_never_applied_to_a_session_it_was_not_made_for(): void
    {
        $this->operator();
        $first = app(TillSessions::class)->current($this->tenant);
        $page = Livewire::test(PointOfSale::class)->call('startClose')->assertSet('closingSessionId', $first->id);

        // Another tab closes it and opens a new one.
        app(TillSessions::class)->close($first, null, 0);
        $second = app(TillSessions::class)->open($this->tenant, null, 30);

        $page->set('countedCash', '30')->call('closeTill')->assertDispatched('pos-print-cancel');
        $this->assertTrue($second->fresh()->isOpen(), 'the new session stays open');
        $this->assertSame(0.0, (float) $first->fresh()->counted_cash);
    }

    public function test_nothing_is_rung_while_the_till_is_closed(): void
    {
        $tee = $this->product('Μπλουζάκι', 8.06, ['price_wvat' => 10.00]);
        $this->operator(open: false);

        Livewire::test(PointOfSale::class)
            ->call('choose', $tee->id)
            ->call('checkout')
            ->assertDispatched('pos-print-cancel');
        $this->assertSame(0, Invoice::count());
    }

    public function test_one_open_till_and_a_closed_one_takes_nothing_more(): void
    {
        $tills = app(TillSessions::class);
        $session = $tills->open($this->tenant, null, 10);
        $this->assertRefused(fn () => $tills->open($this->tenant, null, 10));

        $tills->close($session, null, 10);
        $this->assertRefused(fn () => $tills->move($session, null, PosCashMovement::IN, 5, 'x'));
        $this->assertRefused(fn () => $tills->close($session, null, 10));

        $tee = $this->product('Μπλουζάκι', 8.06, ['price_wvat' => 10.00]);
        $this->assertRefused(fn () => app(CreatePosSale::class)($this->tenant, [['product_id' => $tee->id, 'qty' => 1]], $session));
        $this->assertSame(0, Invoice::count(), 'a sale into a closed till is refused before anything is written');

        // Bad input never opens / moves anything.
        $this->assertRefused(fn () => $tills->open($this->tenant, null, -1));
        $open = $tills->open($this->tenant, null, 0);
        $this->assertRefused(fn () => $tills->move($open, null, PosCashMovement::OUT, 0, 'x'));
        $this->assertRefused(fn () => $tills->move($open, null, PosCashMovement::OUT, 5, '  '));
        $this->assertRefused(fn () => $tills->move($open, null, 'sideways', 5, 'x'));
    }

    public function test_a_session_report_is_only_for_members_of_its_company(): void
    {
        $session = app(TillSessions::class)->open($this->tenant, null, 10);
        $url = PosSessionReportController::signedUrl($session->id);

        Gate::before(fn () => true);
        $stranger = User::create(['name' => 'Άλλος', 'email' => 'till-x-'.uniqid().'@e.test', 'password' => bcrypt('x')]);
        $this->actingAs($stranger)->get($url)->assertForbidden();

        $this->operator(open: false);
        $this->get($url)->assertOk()->assertSee('ΕΝΔΙΑΜΕΣΗ ΑΝΑΦΟΡΑ ΤΑΜΕΙΟΥ');
    }

    public function test_an_expired_report_link_says_so_and_the_closing_link_lives_a_working_day(): void
    {
        $session = app(TillSessions::class)->open($this->tenant, null, 10);
        $this->operator(open: false);

        $expired = URL::temporarySignedRoute('pos.session-report', now()->subMinute(), ['session' => $session->id]);
        $this->get($expired)->assertForbidden()->assertSee('Ο σύνδεσμος έληξε');

        $page = Livewire::test(PointOfSale::class)->call('startClose')->set('countedCash', '10')->call('closeTill');
        $this->travel(11)->hours();
        $page->assertDispatched('pos-print', function ($name, $params) {
            $this->get($params['url'])->assertOk();

            return true;
        });
    }

    public function test_scanning_a_fully_returned_receipt_says_so_once_and_searches_nothing(): void
    {
        $tee = $this->product('Μπλουζάκι', 8.06, ['price_wvat' => 10.00]);
        $this->operator();
        $session = app(TillSessions::class)->current($this->tenant);
        $sale = app(CreatePosSale::class)($this->tenant->fresh(), [['product_id' => $tee->id, 'qty' => 1]], $session);
        app(CreatePosReturn::class)($this->tenant->fresh(), $sale, [$sale->lines()->first()->id => 1], [], $session);

        Livewire::test(PointOfSale::class)
            ->call('scanCode', $sale->receiptCode())
            ->assertSet('returnOf', null)
            ->assertSet('search', '')
            ->assertNotified('Η '.$sale->invcode.' έχει ήδη επιστραφεί ολόκληρη.');
    }

    // ── helpers ────────────────────────────────────────────────────────────

    private function assertRefused(callable $call): void
    {
        try {
            $call();
            $this->fail('expected a refusal');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }
    }

    private function operator(bool $open = true): User
    {
        Gate::before(fn () => true);
        $user = User::create(['name' => 'Ταμίας', 'email' => 'till-'.uniqid().'@e.test', 'password' => bcrypt('x')]);
        $this->tenant->users()->attach($user);
        $this->actingAs($user);
        Filament::setTenant($this->tenant->fresh());
        if ($open) {
            app(TillSessions::class)->open($this->tenant->fresh(), $user, 0);
        }

        return $user;
    }

    private function product(string $name, float $net, array $overrides = []): Product
    {
        return Product::create(array_merge([
            'company_id' => $this->tenant->id,
            'description_short' => $name,
            'product_category_id' => $this->category->id,
            'vat_category_id' => $this->vat->id,
            'sell_price' => $net,
            'price_wvat' => round($net * 1.24, 2),
        ], $overrides));
    }
}
