<?php

namespace Tests\Feature\Portability;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\InvoiceType;
use App\Models\MyDataMark;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\VatCategory;
use App\Services\Portability\CompanyDataWiper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CompanyWipeTest extends TestCase
{
    use RefreshDatabase;

    private function seedCompany(): Company
    {
        $c = Company::create([
            'name' => 'Wipe OE', 'slug' => 'wipe', 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'afm' => '800561849',
        ]);
        VatCategory::create(['company_id' => $c->id, 'description' => '24%', 'rate' => 24, 'is_default' => true]);
        $pm = PaymentMethod::create(['company_id' => $c->id, 'description' => 'Μετρητά', 'due_days' => 0]);
        $type = InvoiceType::create(['company_id' => $c->id, 'code' => 'TPY', 'name' => 'ΤΠΥ', 'invcount' => 9, 'mydata_type' => '2.1']);
        $cust = Customer::create(['company_id' => $c->id, 'name' => 'NEXON', 'afm' => '801280908']);
        $inv = Invoice::create([
            'company_id' => $c->id, 'invcode' => 'TPY1', 'code' => 1,
            'invoice_type_id' => $type->id, 'customer_id' => $cust->id, 'issued_at' => now(), 'header_discount_percent' => 0,
        ]);
        InvoiceLine::create(['company_id' => $c->id, 'invoice_id' => $inv->id, 'qty' => 1, 'price_per_item' => 100, 'vat_percent' => 24, 'product_descr' => 'x']);
        MyDataMark::create(['company_id' => $c->id, 'invoice_id' => $inv->id, 'mark' => '400000000000001', 'mydata_action' => 'INSERT', 'mark_date' => now()->toDateString(), 'mark_time' => now()->toTimeString()]);
        Payment::create(['company_id' => $c->id, 'customer_id' => $cust->id, 'invoice_id' => $inv->id, 'amount' => 124, 'paid_at' => now()]);

        return $c->fresh();
    }

    public function test_wipe_removes_transactional_keeps_settings_and_setup(): void
    {
        $c = $this->seedCompany();

        app(CompanyDataWiper::class)->wipe($c, keepParties: false, resetCounter: false);

        // Transactional gone.
        $this->assertSame(0, Invoice::where('company_id', $c->id)->count());
        $this->assertSame(0, Payment::where('company_id', $c->id)->count());
        $this->assertSame(0, MyDataMark::where('company_id', $c->id)->count());
        $this->assertSame(0, Customer::where('company_id', $c->id)->count());

        // Company + settings + setup kept.
        $this->assertNotNull(Company::find($c->id));
        $this->assertSame(1, InvoiceType::where('company_id', $c->id)->count());
        $this->assertSame(1, VatCategory::where('company_id', $c->id)->count());
        $this->assertSame(1, PaymentMethod::where('company_id', $c->id)->count());
    }

    public function test_keep_parties_preserves_customers(): void
    {
        $c = $this->seedCompany();

        app(CompanyDataWiper::class)->wipe($c, keepParties: true, resetCounter: false);

        $this->assertSame(0, Invoice::where('company_id', $c->id)->count());
        $this->assertSame(1, Customer::where('company_id', $c->id)->count());
    }

    public function test_reset_counter_rolls_invcount_back(): void
    {
        $c = $this->seedCompany();

        app(CompanyDataWiper::class)->wipe($c, keepParties: false, resetCounter: true);

        $this->assertSame(1, InvoiceType::where('company_id', $c->id)->value('invcount'));
    }

    public function test_filed_at_aade_count_and_plan(): void
    {
        $c = $this->seedCompany();
        DB::table('invoices')->where('company_id', $c->id)->update(['mydata_state' => 'VALID']);

        $wiper = app(CompanyDataWiper::class);
        $this->assertSame(1, $wiper->filedAtAadeCount($c));

        $plan = $wiper->plan($c, keepParties: false);
        $this->assertArrayHasKey('invoices', $plan);
        $this->assertArrayHasKey('customers', $plan);
        // plan() is read-only.
        $this->assertSame(1, Invoice::where('company_id', $c->id)->count());
    }
}
