<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\InvoiceTypes\Pages\CreateInvoiceType;
use App\Models\Company;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The «Χρήση πρότασης» one-click on the Invoice Type form: given a series name,
 * it applies the suggested §8.1 type AND back-fills the income chain in one go,
 * so the operator doesn't hand-pick the classification.
 */
class InvoiceTypeSuggestionActionTest extends TestCase
{
    use RefreshDatabase;

    private function boot(): Company
    {
        Gate::before(fn () => true);
        $company = Company::create([
            'name' => 'T', 'slug' => 't-'.uniqid(),
            'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
        $this->actingAs(User::create(['name' => 'U', 'email' => 'u-'.uniqid().'@t.local', 'password' => bcrypt('x')]));
        Filament::setTenant($company);

        return $company;
    }

    public function test_form_renders_with_the_hint_action(): void
    {
        $this->boot();

        Livewire::test(CreateInvoiceType::class)->assertSuccessful();
    }

    public function test_one_click_applies_type_and_income_chain(): void
    {
        $this->boot();

        Livewire::test(CreateInvoiceType::class)
            ->fillForm([
                'name' => 'Τιμολόγιο Παροχής / Ενδοκοινοτική Παροχή Υπηρεσιών',
            ])
            ->callFormComponentAction('mydata_type', 'applyTypeSuggestion')
            ->assertHasNoFormErrors()
            ->assertFormSet([
                'mydata_type' => '2.2',
                'mydata_income_class' => 'E3_561_005',
                'mydata_income_class_category' => 'category1_3',
            ]);
    }

    public function test_one_click_does_not_overwrite_operator_income_pick(): void
    {
        $this->boot();

        Livewire::test(CreateInvoiceType::class)
            ->fillForm([
                'name' => 'Τιμολόγιο πώλησης',
                'mydata_income_class' => 'E3_561_007', // operator already chose something
            ])
            ->callFormComponentAction('mydata_type', 'applyTypeSuggestion')
            ->assertFormSet([
                'mydata_type' => '1.1',
                'mydata_income_class' => 'E3_561_007', // kept, not overwritten
            ]);
    }
}
