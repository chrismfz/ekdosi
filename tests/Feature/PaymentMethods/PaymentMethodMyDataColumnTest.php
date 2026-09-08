<?php

namespace Tests\Feature\PaymentMethods;

use App\Filament\Resources\PaymentMethods\Pages\ListPaymentMethods;
use App\Models\Company;
use App\Models\PaymentMethod;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The «myDATA (§8.12)» column on the Payment Methods list: a mapped method shows
 * its code+label, an UNMAPPED one warns «λείπει → 3» for a tenant that files to
 * AADE (it would be reported as cash) but stays neutral «—» for a non-AADE tenant.
 */
class PaymentMethodMyDataColumnTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(string $provider): Company
    {
        return Company::create([
            'name' => 'PM', 'slug' => 'pm-'.uniqid(),
            'country_code' => 'GR', 'einvoice_provider' => $provider, 'mydata_mode' => 'off',
        ]);
    }

    private function actAsOperator(Company $tenant): void
    {
        $user = User::create([
            'name' => 'Op', 'email' => 'op-'.uniqid().'@example.test', 'password' => bcrypt('x'),
        ]);
        Gate::before(fn () => true);
        $this->actingAs($user);
        Filament::setTenant($tenant);
    }

    public function test_mapped_shows_code_and_unmapped_warns_for_aade_tenant(): void
    {
        $tenant = $this->tenant('gr-mydata');
        $this->actAsOperator($tenant);

        $mapped = PaymentMethod::create([
            'company_id' => $tenant->id, 'description' => 'POS', 'due_days' => 0, 'mydata_payment_type' => 7,
        ]);
        $unmapped = PaymentMethod::create([
            'company_id' => $tenant->id, 'description' => 'Άγνωστο', 'due_days' => 0,
        ]);

        Livewire::test(ListPaymentMethods::class)
            ->loadTable()
            ->assertTableColumnStateSet('mydata_payment_type', '7 · POS/e-POS', record: $mapped)
            ->assertTableColumnStateSet('mydata_payment_type', 'λείπει → 3', record: $unmapped);
    }

    public function test_unmapped_is_neutral_for_a_non_aade_tenant(): void
    {
        $tenant = $this->tenant('none');
        $this->actAsOperator($tenant);

        $unmapped = PaymentMethod::create([
            'company_id' => $tenant->id, 'description' => 'Άγνωστο', 'due_days' => 0,
        ]);

        Livewire::test(ListPaymentMethods::class)
            ->loadTable()
            ->assertTableColumnStateSet('mydata_payment_type', '—', record: $unmapped);
    }
}
