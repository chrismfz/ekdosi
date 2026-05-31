<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Invoices\Pages\CreateInvoice;
use App\Filament\Resources\Quotes\Pages\CreateQuote;
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
}
