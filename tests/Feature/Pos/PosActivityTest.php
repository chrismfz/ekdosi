<?php

namespace Tests\Feature\Pos;

use App\Actions\CreatePosSale;
use App\Filament\Pages\PointOfSale;
use App\Filament\Pages\PosReports;
use App\Models\InvoiceType;
use App\Models\PaymentMethod;
use App\Models\PosEvent;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\VatCategory;
use App\Services\Pos\PosReports as ReportBuilder;
use App\Services\Pos\TillSessions;
use App\Services\TenantRoleProvisioner;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\Feature\Hr\HrTestCase;

/**
 * «Ιστορικό ενεργειών ταμία»: what the cashier does that never becomes a receipt is
 * logged (cart emptied, item taken off, fewer units, discount, typed price, reprint,
 * X report, return opened/cancelled) and shown to the owner in «Αναφορές ταμείου».
 */
class PosActivityTest extends HrTestCase
{
    private Product $tee;

    private Product $any;

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
        $this->any = Product::create(['company_id' => $this->company->id, 'description_short' => 'ΡΟΥΧΑ 24%', 'product_category_id' => $category->id,
            'vat_category_id' => $vat->id, 'sell_price' => 0, 'price_wvat' => 0, 'pos_open_price' => true]);
    }

    public function test_the_cashiers_actions_are_logged_with_their_value(): void
    {
        $anna = $this->actAs($this->makeUser(TenantRoleProvisioner::ROLE_CASHIER));
        $session = app(TillSessions::class)->open($this->company, $anna, 0);
        $old = app(CreatePosSale::class)($this->company, [['product_id' => $this->tee->id, 'qty' => 1]], $session);

        Livewire::test(PointOfSale::class)
            ->call('choose', $this->tee->id)->call('choose', $this->tee->id)   // 2 × 10,00
            ->call('decrement', 0)                                           // one taken back
            ->set('cart.0.discount', 20)                                     // −2,00
            ->call('remove', 0)                                              // the line off (8,00)
            ->call('choose', $this->any->id)->set('promptPrice', '24,90')->call('addOpenPrice')
            ->call('choose', $this->tee->id)
            ->call('clearCart')                                              // 24,90 + 10,00 emptied
            ->call('choose', $this->tee->id)->call('checkout')
            ->call('reprint')
            ->call('printTillReport')
            ->call('scanCode', $old->receiptCode())->call('cancelReturn');

        $events = PosEvent::query()->orderBy('id')->get();
        $this->assertSame(
            [PosEvent::QTY_REDUCED, PosEvent::DISCOUNT, PosEvent::ITEM_REMOVED, PosEvent::OPEN_PRICE, PosEvent::CART_CLEARED,
                PosEvent::REPRINT, PosEvent::X_REPORT, PosEvent::RETURN_OPENED, PosEvent::RETURN_CANCELLED],
            $events->pluck('type')->all(),
        );
        $by = $events->keyBy('type');
        $this->assertSame([10.00, 2.00, 8.00, 24.90, 34.90], [
            (float) $by[PosEvent::QTY_REDUCED]->amount, (float) $by[PosEvent::DISCOUNT]->amount, (float) $by[PosEvent::ITEM_REMOVED]->amount,
            (float) $by[PosEvent::OPEN_PRICE]->amount, (float) $by[PosEvent::CART_CLEARED]->amount,
        ]);
        $this->assertSame('20%', $by[PosEvent::DISCOUNT]->note);
        $this->assertTrue($events->every(fn (PosEvent $e) => $e->user_id === $anna->id && $e->pos_session_id === $session->id));
    }

    public function test_typed_values_are_judged_as_the_till_will_use_them(): void
    {
        $anna = $this->actAs($this->makeUser(TenantRoleProvisioner::ROLE_CASHIER));
        $session = app(TillSessions::class)->open($this->company, $anna, 0);
        $old = app(CreatePosSale::class)($this->company, [['product_id' => $this->tee->id, 'qty' => 1]], $session);

        Livewire::test(PointOfSale::class)
            ->call('choose', $this->tee->id)
            ->set('cart.0.discount', 150)      // clamped to 100% — logged as a 10,00 discount
            ->set('cart.0.discount', 0)
            ->set('cart.0.qty', '')            // emptied → 0.001 — the unit is gone
            ->call('scanCode', $old->receiptCode())
            ->call('clearCart');                // «Άδειασμα» during a return = the return cancelled

        $events = PosEvent::query()->orderBy('id')->get();
        $this->assertSame([PosEvent::DISCOUNT, PosEvent::QTY_REDUCED, PosEvent::RETURN_OPENED, PosEvent::RETURN_CANCELLED, PosEvent::CART_CLEARED], $events->pluck('type')->all());
        $this->assertSame(['100%', 10.00], [$events[0]->note, (float) $events[0]->amount]);
        $this->assertSame(0.999, (float) $events[1]->qty);
    }

    public function test_the_owner_sees_them_per_cashier_in_the_period_and_in_the_sessions_timeline(): void
    {
        $anna = $this->actAs($this->makeUser(TenantRoleProvisioner::ROLE_CASHIER));
        $session = app(TillSessions::class)->open($this->company, $anna, 0);
        Livewire::test(PointOfSale::class)
            ->call('choose', $this->tee->id)->call('clearCart')
            ->call('choose', $this->tee->id)->call('remove', 0)
            ->call('choose', $this->tee->id)->call('checkout')->call('reprint');

        $r = app(ReportBuilder::class)->build($this->company, now(), now());
        $row = collect($r['by_cashier'])->firstWhere('user_id', $anna->id);
        $this->assertSame([1, 10.00, 1, 10.00, 1], [$row['cleared'], $row['cleared_amount'], $row['removed'], $row['removed_amount'], $row['reprints']]);
        $this->assertSame(3, $r['events']->count(), 'cleared, removed, reprint — the ones worth a look');

        $this->actAs($this->makeUser(TenantRoleProvisioner::ROLE_COMPANY_ADMIN));
        Livewire::test(PosReports::class)
            ->assertSee('Ενέργειες ταμία προς έλεγχο')->assertSee('Άδειασμα καλαθιού')->assertSee('Αφαίρεση είδους')
            ->call('toggleSession', $session->id)
            ->assertSee('Χρονολόγιο')->assertSee('Επανεκτύπωση')->assertSee('Απόδειξη ΑΛΠ');
    }
}
