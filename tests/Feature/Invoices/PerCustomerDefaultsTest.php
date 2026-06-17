<?php

namespace Tests\Feature\Invoices;

use App\Filament\Resources\Invoices\Pages\CreateInvoice;
use App\Models\Company;
use App\Models\Customer;
use App\Models\InvoiceType;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Models\VatCategory;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Per-customer commercial defaults flow into a new invoice: picking a customer
 * applies their standing «Default discount %» to the header, and their default
 * payment method as a FALLBACK — the invoice type's payment method wins when set.
 */
class PerCustomerDefaultsTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private PaymentMethod $cash;

    private PaymentMethod $credit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Company::create([
            'name' => 'Defaults Co', 'slug' => 'def-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off', 'afm' => '800000000',
        ]);
        VatCategory::create(['company_id' => $this->tenant->id, 'description' => '24%', 'rate' => 24, 'is_default' => true]);
        $this->cash = PaymentMethod::create(['company_id' => $this->tenant->id, 'description' => 'Μετρητά', 'due_days' => 0]);
        $this->credit = PaymentMethod::create(['company_id' => $this->tenant->id, 'description' => 'Επί πιστώσει', 'due_days' => 30]);

        Gate::before(fn () => true);
        $this->actingAs(User::create([
            'name' => 'Op', 'email' => 'op-'.uniqid().'@example.test', 'password' => bcrypt('x'),
        ]));
        Filament::setTenant($this->tenant);
    }

    public function test_selecting_customer_applies_discount_and_payment_when_type_has_none(): void
    {
        $customer = Customer::create([
            'company_id' => $this->tenant->id, 'name' => 'Εκπτωτικός',
            'discount' => 10, 'payment_method_id' => $this->credit->id,
        ]);

        Livewire::test(CreateInvoice::class)
            ->fillForm(['customer_id' => $customer->id])
            ->assertFormSet([
                'header_discount_percent' => 10.0,
                'payment_method_id' => $this->credit->id,
            ]);
    }

    public function test_invoice_type_payment_method_wins_over_customer_default(): void
    {
        // A type that pins «Μετρητά».
        $type = InvoiceType::create([
            'company_id' => $this->tenant->id, 'code' => 'APY', 'name' => 'Απόδειξη',
            'invcount' => 1, 'payment_method_id' => $this->cash->id,
        ]);
        $customer = Customer::create([
            'company_id' => $this->tenant->id, 'name' => 'Πιστωτικός',
            'discount' => 5, 'payment_method_id' => $this->credit->id,
        ]);

        Livewire::test(CreateInvoice::class)
            // Type first → sets payment to cash; then customer → discount applies
            // but payment is NOT clobbered (type wins, customer is fallback only).
            ->fillForm(['invoice_type_id' => $type->id])
            ->fillForm(['customer_id' => $customer->id])
            ->assertFormSet([
                'header_discount_percent' => 5.0,
                'payment_method_id' => $this->cash->id,
            ]);
    }

    public function test_customer_with_no_discount_resets_header_to_zero(): void
    {
        $customer = Customer::create([
            'company_id' => $this->tenant->id, 'name' => 'Χωρίς έκπτωση', 'payment_method_id' => null,
        ]);

        Livewire::test(CreateInvoice::class)
            ->fillForm(['header_discount_percent' => 15])     // operator typed something
            ->fillForm(['customer_id' => $customer->id])       // picking the customer resets it
            ->assertFormSet(['header_discount_percent' => 0.0]);
    }
}
