<?php

namespace Tests\Feature\Pos;

use App\Actions\PosSaleNotIssued;
use App\Filament\Pages\Assistant;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\MySessions;
use App\Filament\Pages\PointOfSale;
use App\Filament\Resources\LeaveRequests\LeaveRequestResource;
use App\Filament\Support\ManageTenantRoleAction;
use App\Http\Controllers\PosReceiptController;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\VatCategory;
use App\Services\Pos\TillSessions;
use App\Services\Products\ProductMediaService;
use App\Services\TenantRoleProvisioner;
use App\Support\Hr\ErganiStaff;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use ReflectionMethod;
use Spatie\Permission\Models\Permission;
use Tests\Feature\Hr\HrTestCase;

/**
 * «Ταμίας — μόνο Ταμείο» (POS PR 2c): a managed role that rings sales/returns and
 * opens/closes the till — and sees nothing else of the panel (default-deny, like
 * `ergani`), gets no operator broadcasts, and is never offered links it can't open.
 */
class PosCashierRoleTest extends HrTestCase
{
    private InvoiceType $receipt;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::findOrCreate('View:PointOfSale', 'web');
        app(TenantRoleProvisioner::class)->ensureStandardRoles($this->company);

        $vat = VatCategory::create(['company_id' => $this->company->id, 'description' => '24%', 'rate' => 24, 'is_default' => true]);
        $this->receipt = InvoiceType::create(['company_id' => $this->company->id, 'code' => 'ΑΛΠ', 'name' => 'Απόδειξη Λιανικής Πώλησης', 'invcount' => 1, 'mydata_type' => '11.1']);
        $cash = PaymentMethod::create(['company_id' => $this->company->id, 'description' => 'Μετρητά', 'due_days' => 0, 'mydata_payment_type' => 3]);
        $this->company->update(['pos_enabled' => true, 'pos_invoice_type_id' => $this->receipt->id, 'pos_payment_method_id' => $cash->id]);
        $category = ProductCategory::create(['company_id' => $this->company->id, 'description_short' => 'Ένδυση', 'markup' => 0]);
        Product::create(['company_id' => $this->company->id, 'description_short' => 'Μπλουζάκι', 'product_category_id' => $category->id,
            'vat_category_id' => $vat->id, 'sell_price' => 8.06, 'price_wvat' => 10.00]);
    }

    public function test_the_cashier_role_holds_only_the_till_and_their_own_staff_screens(): void
    {
        $role = app(TenantRoleProvisioner::class)->findManagedRole(TenantRoleProvisioner::ROLE_CASHIER, $this->company);
        $this->assertEqualsCanonicalizing(
            ['View:PointOfSale', 'ViewAny:LeaveRequest', 'View:LeaveRequest', 'Create:LeaveRequest', 'View:LeaveCalendar'],
            $role->permissions()->pluck('name')->all(),   // (View:WorkCard is not generated in this test DB)
        );
        $this->assertSame('Ταμίας (μόνο Ταμείο)', ManageTenantRoleAction::roleLabel(TenantRoleProvisioner::ROLE_CASHIER));
        $options = (new ReflectionMethod(ManageTenantRoleAction::class, 'roleOptions'))->invoke(null, $this->company);
        $this->assertArrayHasKey(TenantRoleProvisioner::ROLE_CASHIER, $options);
    }

    public function test_a_cashier_lands_on_the_till_and_nothing_else_opens(): void
    {
        $user = $this->actAs($this->makeUser(TenantRoleProvisioner::ROLE_CASHIER));
        $slug = $this->company->slug;
        $till = PointOfSale::getUrl(tenant: $this->company);

        $this->assertSame(TenantRoleProvisioner::ROLE_CASHIER, ErganiStaff::restrictedRole($user, $this->company));
        $this->assertFalse(Dashboard::canAccess());
        $this->assertFalse(Assistant::assistantAvailable());

        $this->get("/admin/{$slug}")->assertRedirect();
        $this->get("/admin/{$slug}/invoices")->assertRedirect($till);
        $this->get("/admin/{$slug}/customers")->assertRedirect($till);
        $this->get("/admin/{$slug}/my-data-reconciliation")->assertRedirect($till);
        $this->get($till)->assertOk()->assertSee('Το ταμείο είναι κλειστό');
        $this->get(LeaveRequestResource::getUrl('index', tenant: $this->company))->assertOk();
        $this->get(MySessions::getUrl(tenant: $this->company))->assertOk();
    }

    public function test_an_ergani_user_still_lands_on_their_leave_screens_not_the_till(): void
    {
        $this->actAs($this->makeUser(TenantRoleProvisioner::ROLE_ERGANI));
        $this->get(PointOfSale::getUrl(tenant: $this->company))->assertRedirect(LeaveRequestResource::getUrl('index', tenant: $this->company));
    }

    public function test_a_cashier_runs_the_till_and_prints_its_receipt(): void
    {
        $user = $this->actAs($this->makeUser(TenantRoleProvisioner::ROLE_CASHIER));
        $product = Product::query()->where('company_id', $this->company->id)->sole();

        Livewire::test(PointOfSale::class)
            ->set('openingFloat', '20')->call('openTill')
            ->call('choose', $product->id)
            ->call('checkout')
            ->assertDispatched('pos-print');

        $sale = Invoice::query()->where('company_id', $this->company->id)->sole();
        $this->assertSame('active', $sale->local_status);
        $this->assertNotNull($sale->pos_session_id);
        $this->get(PosReceiptController::signedUrl($sale->id))->assertOk();

        // Broadcasts (new invoice, payment…) never reach the cashier.
        $this->assertNotContains($user->id, ErganiStaff::staffRecipients($this->company)->pluck('id')->all());
    }

    public function test_a_stuck_draft_notice_offers_no_invoice_link_to_a_cashier(): void
    {
        $this->actAs($this->makeUser(TenantRoleProvisioner::ROLE_CASHIER));
        app(TillSessions::class)->open($this->company, null, 0);
        $draft = Invoice::create(['company_id' => $this->company->id, 'invoice_type_id' => $this->receipt->id, 'issued_at' => now(), 'local_status' => 'draft', 'header_discount_percent' => 0]);

        $page = Livewire::test(PointOfSale::class);
        $method = new ReflectionMethod(PointOfSale::class, 'draftNotice');
        $method->invoke($page->instance(), Notification::make()->title('Δεν εκδόθηκε')
            ->body((new PosSaleNotIssued($draft->id, new \RuntimeException('AADE down')))->getMessage()), $draft->id);

        $sent = collect(session()->get('filament.notifications', []))->last();
        $this->assertSame([], $sent['actions'] ?? []);
        $this->assertStringContainsString('Ενημέρωσε τον υπεύθυνο για το πρόχειρο #'.$draft->id, $sent['body']);
    }

    public function test_an_operator_still_gets_the_link_to_the_stuck_draft(): void
    {
        $this->actAs($this->makeUser(TenantRoleProvisioner::ROLE_OPERATOR));
        $draft = Invoice::create(['company_id' => $this->company->id, 'invoice_type_id' => $this->receipt->id, 'issued_at' => now(), 'local_status' => 'draft', 'header_discount_percent' => 0]);

        $page = Livewire::test(PointOfSale::class);
        (new ReflectionMethod(PointOfSale::class, 'draftNotice'))->invoke($page->instance(), Notification::make()->title('Δεν εκδόθηκε')->body('x'), $draft->id);

        $sent = collect(session()->get('filament.notifications', []))->last();
        $this->assertStringContainsString('/invoices/'.$draft->id, $sent['actions'][0]['url'] ?? '');
    }

    public function test_a_cashier_sees_the_product_photos_on_the_till(): void
    {
        Storage::fake('local');
        $product = Product::query()->where('company_id', $this->company->id)->sole();
        $img = imagecreatetruecolor(300, 300);
        $path = tempnam(sys_get_temp_dir(), 'pm').'.jpg';
        imagejpeg($img, $path);
        $media = app(ProductMediaService::class)->storeImage($product, $path, 'tee.jpg');

        $this->actAs($this->makeUser(TenantRoleProvisioner::ROLE_CASHIER));
        $this->get(route('product-media.show', ['media' => $media->id, 'variant' => 'thumb']))->assertOk();
    }

    public function test_a_cashier_of_a_shop_whose_till_is_off_lands_on_their_leave_screens(): void
    {
        $this->company->update(['pos_enabled' => false]);
        $this->actAs($this->makeUser(TenantRoleProvisioner::ROLE_CASHIER));

        $this->get('/admin/'.$this->company->slug.'/invoices')->assertRedirect(LeaveRequestResource::getUrl('index', tenant: $this->company));
    }
}
