<?php

namespace Tests\Feature\Pos;

use App\Actions\CreatePosReturn;
use App\Actions\CreatePosSale;
use App\Filament\Pages\PosStatsDashboard;
use App\Filament\PosStats\Widgets\PosBusyHours;
use App\Filament\PosStats\Widgets\PosCashierShareChart;
use App\Filament\PosStats\Widgets\PosCumulativeChart;
use App\Filament\PosStats\Widgets\PosDailyChart;
use App\Filament\PosStats\Widgets\PosMonthlyChart;
use App\Filament\PosStats\Widgets\PosStatsKpis;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\VatCategory;
use App\Services\Pos\PosStats;
use App\Services\Pos\TillSessions;
use App\Services\TenantRoleProvisioner;
use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\Feature\Hr\HrTestCase;

/**
 * «Ταμεία — στατιστικά»: the till's turnover per day / month / year against a
 * comparison year, busy hours, share per cashier — till documents only, a return
 * subtracts; company_admin / super_admin only.
 */
class PosStatsTest extends HrTestCase
{
    private Product $tee;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['View:PointOfSale', 'View:PosStatsDashboard'] as $p) {
            Permission::findOrCreate($p, 'web');
        }
        app(TenantRoleProvisioner::class)->ensureStandardRoles($this->company);

        $vat = VatCategory::create(['company_id' => $this->company->id, 'description' => '24%', 'rate' => 24, 'is_default' => true]);
        $receipt = InvoiceType::create(['company_id' => $this->company->id, 'code' => 'ΑΛΠ', 'name' => 'Απόδειξη Λιανικής Πώλησης', 'invcount' => 1, 'mydata_type' => '11.1']);
        $credit = InvoiceType::create(['company_id' => $this->company->id, 'code' => 'ΠΙΛ', 'name' => 'Πιστωτικό Λιανικής', 'invcount' => 1, 'mydata_type' => '11.4', 'is_credit' => true]);
        $cash = PaymentMethod::create(['company_id' => $this->company->id, 'description' => 'Μετρητά', 'due_days' => 0, 'mydata_payment_type' => 3]);
        $this->company->update(['pos_enabled' => true, 'pos_invoice_type_id' => $receipt->id, 'pos_payment_method_id' => $cash->id, 'pos_credit_type_id' => $credit->id]);
        $category = ProductCategory::create(['company_id' => $this->company->id, 'description_short' => 'Ένδυση', 'markup' => 0]);
        $this->tee = Product::create(['company_id' => $this->company->id, 'description_short' => 'Μπλουζάκι', 'product_category_id' => $category->id,
            'vat_category_id' => $vat->id, 'sell_price' => 8.06, 'price_wvat' => 10.00]);
    }

    public function test_turnover_per_day_month_and_year_against_last_year_with_returns_and_busy_hours(): void
    {
        $anna = $this->makeUser(TenantRoleProvisioner::ROLE_CASHIER);
        $this->actingAs($anna);

        // Last year, 5 March 11:00: one 10,00 sale. This year, 5 March 11:00: two sales (30,00) + a 10,00 return; 20 March 18:00: 10,00.
        $this->sellAt(CarbonImmutable::create(2025, 3, 5, 11), 1);
        $sale = $this->sellAt(CarbonImmutable::create(2026, 3, 5, 11), 2);
        $this->sellAt(CarbonImmutable::create(2026, 3, 5, 11, 30), 1);
        $this->travelTo(CarbonImmutable::create(2026, 3, 5, 12));
        $session = app(TillSessions::class)->current($this->company) ?? app(TillSessions::class)->open($this->company, $anna, 0);
        app(CreatePosReturn::class)($this->company, $sale, [$sale->lines()->first()->id => 1], [], $session);
        $this->sellAt(CarbonImmutable::create(2026, 3, 20, 18), 1);
        $this->travelBack();

        $stats = new PosStats($this->company);
        $march = $stats->totals(CarbonImmutable::create(2026, 3, 1), CarbonImmutable::create(2026, 3, 31));
        $this->assertSame([40.00, 10.00, 30.00, 3, 1, 13.33], [$march['sales'], $march['refunds'], $march['net'], $march['receipts'], $march['returns'], $march['avg_basket']]);
        $this->assertSame(10.00, $stats->totals(CarbonImmutable::create(2025, 3, 1), CarbonImmutable::create(2025, 3, 31))['net']);
        $this->assertSame(200.0, PosStats::delta(30.00, 10.00));
        $this->assertNull(PosStats::delta(5.0, 0.0));

        $daily = $stats->dailyNet(2026, 3);
        $this->assertCount(31, $daily);
        $this->assertSame([20.00, 10.00], [$daily[4], $daily[19]], '5/3: 30 − 10 · 20/3: 10');
        $this->assertSame(30.00, $stats->monthlyNet(2026)[2]);
        $this->assertSame(10.00, $stats->monthlyNet(2025)[2]);

        $hours = $stats->weekdayHours(CarbonImmutable::create(2026, 3, 1), CarbonImmutable::create(2026, 3, 31));
        $this->assertSame(2, $hours['receipts'][4][11], 'Thursday 5/3 at 11:xx — two receipts (the return is not one)');
        $this->assertSame(1, $hours['receipts'][5][18], 'Friday 20/3 at 18:00');
        $this->assertSame([30.00], array_values($stats->netByCashier(CarbonImmutable::create(2026, 3, 1), CarbonImmutable::create(2026, 3, 31))));
    }

    public function test_only_admins_see_it_and_every_widget_renders(): void
    {
        foreach ([TenantRoleProvisioner::ROLE_OPERATOR, TenantRoleProvisioner::ROLE_CASHIER] as $role) {
            $this->actAs($this->makeUser($role));
            $this->assertFalse(PosStatsDashboard::canAccess(), $role);
        }

        $this->actAs($this->makeUser(TenantRoleProvisioner::ROLE_COMPANY_ADMIN));
        $this->assertTrue(PosStatsDashboard::canAccess());
        $this->sellAt(CarbonImmutable::now()->startOfDay()->setHour(10), 1);

        $filters = ['year' => (int) now()->year, 'month' => (int) now()->month, 'compare_year' => (int) now()->year - 1];
        foreach ([PosStatsKpis::class, PosDailyChart::class, PosMonthlyChart::class, PosCumulativeChart::class, PosCashierShareChart::class, PosBusyHours::class] as $widget) {
            Livewire::test($widget, ['pageFilters' => $filters])->assertOk();
        }
        Livewire::test(PosStatsKpis::class, ['pageFilters' => $filters])->assertSee('Σήμερα')->assertSee('10,00');
        $this->get(PosStatsDashboard::getUrl(tenant: $this->company))->assertOk();
    }

    public function test_the_year_to_date_comparison_is_like_for_like_even_on_29_february(): void
    {
        $this->sellAt(CarbonImmutable::create(2027, 3, 1, 10), 1);   // 1 March of the comparison year — outside «ως 29/2»
        $this->sellAt(CarbonImmutable::create(2027, 2, 28, 10), 1);
        $this->sellAt(CarbonImmutable::create(2028, 2, 29, 10), 2);
        $this->actAs($this->makeUser(TenantRoleProvisioner::ROLE_COMPANY_ADMIN));
        $this->travelTo(CarbonImmutable::create(2028, 2, 29, 12));

        Livewire::test(PosStatsKpis::class, ['pageFilters' => ['year' => 2028, 'month' => 2, 'compare_year' => 2027]])
            ->assertSee('Έτος 2028 ως 29/02')
            ->assertSee('+100,0% vs ίδιο διάστημα 2027 (10,00');
    }

    private function sellAt(CarbonImmutable $at, int $qty): Invoice
    {
        $this->travelTo($at);
        $tills = app(TillSessions::class);
        $session = $tills->current($this->company) ?? $tills->open($this->company, null, 0);
        $sale = app(CreatePosSale::class)($this->company, [['product_id' => $this->tee->id, 'qty' => $qty]], $session);
        $this->travelBack();

        return $sale;
    }
}
