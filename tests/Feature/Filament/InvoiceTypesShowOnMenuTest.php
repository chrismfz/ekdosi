<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\InvoiceTypes\Pages\ListInvoiceTypes;
use App\Models\Company;
use App\Models\InvoiceType;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

class InvoiceTypesShowOnMenuTest extends TestCase
{
    use RefreshDatabase;

    public function test_show_all_on_menu_flips_hidden_types(): void
    {
        Gate::before(fn () => true);

        $company = Company::create([
            'name' => 'T', 'slug' => 't-'.uniqid(),
            'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);

        InvoiceType::create(['company_id' => $company->id, 'code' => 'ΤΙΜ', 'name' => 'Τιμολόγιο', 'invcount' => 1, 'mydata_type' => '1.1', 'show_on_menu' => false]);
        InvoiceType::create(['company_id' => $company->id, 'code' => 'ΑΛΠ', 'name' => 'Λιανική', 'invcount' => 1, 'mydata_type' => '11.1', 'show_on_menu' => false]);
        InvoiceType::create(['company_id' => $company->id, 'code' => 'ΤΠΥ', 'name' => 'Υπηρεσίες', 'invcount' => 1, 'mydata_type' => '2.1', 'show_on_menu' => true]);

        $this->actingAs(User::create(['name' => 'U', 'email' => 'u-'.uniqid().'@t.local', 'password' => bcrypt('x')]));
        Filament::setTenant($company);

        Livewire::test(ListInvoiceTypes::class)
            ->callAction('show_all_on_menu')
            ->assertHasNoActionErrors();

        $this->assertSame(
            0,
            InvoiceType::where('company_id', $company->id)->where('show_on_menu', false)->count(),
            'no invoice type should be off-menu after the action',
        );
    }

    public function test_invoices_resource_label_is_greek(): void
    {
        // The last English nav item is now «Παραστατικά».
        $this->assertSame('Παραστατικά', InvoiceResource::getPluralModelLabel());
        $this->assertSame('παραστατικό', InvoiceResource::getModelLabel());
    }
}
