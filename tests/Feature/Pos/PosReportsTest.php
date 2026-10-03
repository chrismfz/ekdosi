<?php

namespace Tests\Feature\Pos;

use App\Actions\CreatePosReturn;
use App\Actions\CreatePosSale;
use App\Filament\Pages\PosReports;
use App\Models\InvoiceType;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Models\VatCategory;
use App\Services\Pos\PosReports as ReportBuilder;
use App\Services\Pos\TillSessions;
use App\Services\TenantRoleProvisioner;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\Feature\Hr\HrTestCase;

/**
 * «Αναφορές Ταμείου»: who rang what (pos_cashier_id), per cashier / day / method /
 * VAT for a period, and every till session with its count — for company_admin /
 * super_admin only.
 */
class PosReportsTest extends HrTestCase
{
    private Product $tee;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['View:PointOfSale', 'View:PosReports'] as $p) {
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

    public function test_who_rang_what_per_cashier_with_discounts_and_the_counts_they_closed(): void
    {
        $anna = $this->makeUser(TenantRoleProvisioner::ROLE_CASHIER);
        $nikos = $this->makeUser(TenantRoleProvisioner::ROLE_CASHIER);
        $tills = app(TillSessions::class);
        $session = $tills->open($this->company, $anna, 50);

        $this->actingAs($anna);
        $sale = app(CreatePosSale::class)($this->company, [['product_id' => $this->tee->id, 'qty' => 2]], $session);
        $this->actingAs($nikos);
        app(CreatePosSale::class)($this->company, [['product_id' => $this->tee->id, 'qty' => 1, 'discount' => 10]], $session);
        app(CreatePosReturn::class)($this->company, $sale, [$sale->lines()->first()->id => 1], [], $session);
        $this->assertSame($anna->id, $sale->fresh()->pos_cashier_id, 'the document records who rang it');

        $tills->close($session, $nikos, 69.00);   // expected 50 + 20 + 9 − 10 = 69

        $r = app(ReportBuilder::class)->build($this->company, now(), now());
        $this->assertSame(29.00, $r['totals']['sales_total']);
        $this->assertSame(10.00, $r['totals']['refunds_total']);
        $this->assertSame(19.00, $r['totals']['net_total']);
        $this->assertSame(1.00, $r['totals']['discounts'], 'the 10% on one 10,00 item');
        $this->assertSame(14.50, $r['avg_basket']);

        $rows = collect($r['by_cashier'])->keyBy('user_id');
        $this->assertSame([1, 20.00, 0, 20.00], [$rows[$anna->id]['sales_count'], $rows[$anna->id]['sales'], $rows[$anna->id]['refunds_count'], $rows[$anna->id]['net']]);
        $this->assertSame([1, 9.00, 1, 10.00, 1.00, 1, 0.0], [
            $rows[$nikos->id]['sales_count'], $rows[$nikos->id]['sales'], $rows[$nikos->id]['refunds_count'],
            $rows[$nikos->id]['refunds'], $rows[$nikos->id]['discounts'], $rows[$nikos->id]['sessions_closed'], $rows[$nikos->id]['difference'],
        ]);
        $this->assertSame(1, $r['sessions']->count());

        // The cashier filter narrows to their documents (and the sessions they opened/closed).
        $only = app(ReportBuilder::class)->build($this->company, now(), now(), $anna->id);
        $this->assertSame(20.00, $only['totals']['net_total']);
        $this->assertSame(1, $only['sessions']->count(), 'anna opened it');

        // Another day sees nothing.
        $this->assertSame(0, app(ReportBuilder::class)->build($this->company, now()->subDays(3), now()->subDays(2))['totals']['sales_count']);
    }

    public function test_a_manager_who_only_closes_keeps_the_difference_and_a_till_belongs_to_its_closing_day(): void
    {
        $anna = $this->makeUser(TenantRoleProvisioner::ROLE_CASHIER);
        $boss = $this->makeUser(TenantRoleProvisioner::ROLE_COMPANY_ADMIN);
        $tills = app(TillSessions::class);

        $this->travelTo(now()->subDay()->setTime(23, 30));
        $session = $tills->open($this->company, $anna, 50);
        $this->actingAs($anna);
        app(CreatePosSale::class)($this->company, [['product_id' => $this->tee->id, 'qty' => 1]], $session);
        $this->travelBack();
        app(CreatePosSale::class)($this->company, [['product_id' => $this->tee->id, 'qty' => 1]], $session);
        $tills->close($session, $boss, 65.00);   // expected 70 → −5, counted TODAY by the manager

        $today = app(ReportBuilder::class)->build($this->company, now(), now());
        $this->assertSame(1, $today['sessions']->count(), 'counted today → today\'s till');
        $this->assertSame(-5.0, $today['difference_total']);
        $rows = collect($today['by_cashier'])->keyBy('user_id');
        $this->assertSame([1, -5.0], [$rows[$boss->id]['sessions_closed'], $rows[$boss->id]['difference']], 'the closer has a row even with no sales');
        $this->assertSame(10.00, $rows[$anna->id]['sales']);

        $this->assertSame(0, app(ReportBuilder::class)->build($this->company, now()->subDay(), now()->subDay())['sessions']->count());
        $this->assertSame(0.0, app(ReportBuilder::class)->build($this->company, now(), now(), $anna->id)['difference_total'], 'anna did not count it');
    }

    public function test_only_admins_see_the_page_and_it_renders_a_session(): void
    {
        $tills = app(TillSessions::class);
        $session = $tills->open($this->company, null, 20);
        app(CreatePosSale::class)($this->company, [['product_id' => $this->tee->id, 'qty' => 1]], $session);
        $tills->close($session, null, 29.50);

        foreach ([TenantRoleProvisioner::ROLE_OPERATOR, TenantRoleProvisioner::ROLE_CASHIER] as $role) {
            $this->actAs($this->makeUser($role));
            $this->assertFalse(PosReports::canAccess(), $role);
        }

        $this->actAs($this->makeUser(TenantRoleProvisioner::ROLE_COMPANY_ADMIN));
        $this->assertTrue(PosReports::canAccess());
        Livewire::test(PosReports::class)
            ->assertSee('Καθαρός τζίρος')
            ->assertSee('#'.$session->id)
            ->call('toggleSession', $session->id)
            ->assertSee('Αναμενόμενα μετρητά')
            ->assertSee('−0,50 €')
            ->assertSee('Αναφορά ταμείου (80mm)');
    }

    public function test_a_crafted_cashier_id_from_another_company_is_ignored(): void
    {
        $stranger = User::create(['name' => 'Ξένος', 'email' => 'x-'.uniqid().'@e.test', 'password' => bcrypt('x')]);
        $this->actAs($this->makeUser(TenantRoleProvisioner::ROLE_COMPANY_ADMIN));

        $page = Livewire::test(PosReports::class)->set('cashier', (string) $stranger->id);
        $this->assertSame(0, $page->instance()->getResult()['totals']['sales_count']);
        $page->assertOk();
    }
}
