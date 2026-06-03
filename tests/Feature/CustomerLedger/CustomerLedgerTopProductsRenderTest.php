<?php

namespace Tests\Feature\CustomerLedger;

use App\Filament\Resources\Customers\Pages\CustomerLedger;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\InvoiceType;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Models\VatCategory;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Renders the Καρτέλα for a customer WITH activity, exercising the new
 * «Συχνά προϊόντα» panel + header enrichments (the `@else` branch of the
 * blade that the no-activity mount test never reaches).
 */
class CustomerLedgerTopProductsRenderTest extends TestCase
{
    use RefreshDatabase;

    public function test_kartela_renders_top_products_panel_and_enriched_header(): void
    {
        Gate::before(fn () => true);

        $tenant = Company::create([
            'name' => 'T', 'slug' => 'tpr-'.uniqid(),
            'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
        $pm = PaymentMethod::create(['company_id' => $tenant->id, 'description' => 'Μετρητά', 'due_days' => 0, 'is_active' => true]);
        $invType = InvoiceType::create([
            'company_id' => $tenant->id, 'name' => 'TPY', 'code' => 'TPY', 'invcount' => 0, 'payment_method_id' => $pm->id,
        ]);
        $cat = ProductCategory::create(['company_id' => $tenant->id, 'description_short' => 'Γενικά']);
        $vat = VatCategory::create(['company_id' => $tenant->id, 'description' => '24%', 'rate' => 24]);

        $customer = Customer::create([
            'company_id' => $tenant->id, 'name' => 'Πελάτης Α',
            'phone1' => '2310000000', 'email' => 'a@b.gr', 'discount' => 5,
            'payment_method_id' => $pm->id, 'needs_immediate_invoice' => true,
        ]);

        $product = Product::create([
            'company_id' => $tenant->id, 'sku' => 'UPS1', 'description_short' => 'UPS 850VA',
            'product_category_id' => $cat->id, 'vat_category_id' => $vat->id,
        ]);

        $inv = Invoice::create([
            'company_id' => $tenant->id, 'customer_id' => $customer->id, 'invoice_type_id' => $invType->id,
            'invcode' => 'TPY1', 'code' => 1, 'issued_at' => '2026-03-10',
            'gross_total' => 124, 'net_total' => 100, 'local_status' => 'active',
        ]);
        InvoiceLine::create([
            'company_id' => $tenant->id, 'invoice_id' => $inv->id, 'product_id' => $product->id,
            'qty' => 1, 'price_per_item' => 100, 'vat_percent' => 24, 'product_descr' => 'UPS 850VA', 'metric_unit' => 'ΤΕΜ',
        ]);

        $this->actingAs(User::create(['name' => 'Op', 'email' => 'op-'.uniqid().'@t.l', 'password' => bcrypt('x')]));
        Filament::setTenant($tenant);

        Livewire::test(CustomerLedger::class, ['record' => $customer->id])
            ->assertStatus(200)
            ->assertSee('Συχνά προϊόντα/υπηρεσίες')
            ->assertSee('UPS 850VA')
            ->assertSee('Άμεση τιμολόγηση')   // header badge
            ->assertSee('Μετρητά');           // payment method in header
    }
}
