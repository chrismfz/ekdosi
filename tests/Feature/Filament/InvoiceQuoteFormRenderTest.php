<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Invoices\Pages\CreateInvoice;
use App\Filament\Resources\Quotes\Pages\CreateQuote;
use App\Models\BankAccount;
use App\Models\Company;
use App\Models\InvoiceType;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Smoke test that the single-page / Excel-style (Repeater->table()) forms boot.
 * The `->table()` layout + live product selects can't be unit-tested for
 * behaviour, but a Livewire mount proves the schema is valid and renders —
 * the same "does it actually load" guard the ExpensesListFiltersTest lesson
 * established for Filament screens.
 */
class InvoiceQuoteFormRenderTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Company::create([
            'name' => 'Form', 'slug' => 'form-'.uniqid(),
            'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'sandbox',
            'afm' => '800000000',
        ]);
        InvoiceType::create([
            'company_id' => $this->tenant->id, 'code' => 'TPY', 'name' => 'Τιμολόγιο',
            'invcount' => 1, 'show_on_menu' => true,
        ]);

        $user = User::create([
            'name' => 'Op', 'email' => 'op-'.uniqid().'@example.test', 'password' => bcrypt('x'),
        ]);
        Gate::before(fn () => true);
        $this->actingAs($user);
        Filament::setTenant($this->tenant);
    }

    public function test_create_quote_form_renders(): void
    {
        Livewire::test(CreateQuote::class)->assertOk();
    }

    public function test_create_invoice_form_renders(): void
    {
        Livewire::test(CreateInvoice::class)->assertOk();
    }

    public function test_create_invoice_validates_with_a_visible_bank_account_field(): void
    {
        // Production regression: BankAccountField (a Select) registered its tenant-guard
        // as a BARE Laravel closure in ->rules([...]); Filament v5 evaluates every rule
        // closure, so it tried to resolve $attribute → "closure … [$attribute] was
        // unresolvable" on EVERY invoice/payment create — but ONLY when the field is
        // VISIBLE, i.e. the tenant has an active bank account (else it's hidden and the
        // rule never evaluates, which is why the suite missed it). Give the tenant an
        // account so the field is visible, then submit: computing the schema's validation
        // rules must NOT throw — it must reach normal form errors for the empty form.
        BankAccount::create([
            'company_id' => $this->tenant->id,
            'bank_name' => 'Πειραιώς',
            'iban' => 'GR1601100000000000000000001',
            'is_active' => true,
        ]);

        Livewire::test(CreateInvoice::class)
            ->call('create')
            ->assertHasFormErrors(); // reached validation (empty form) without the container crash
    }
}
