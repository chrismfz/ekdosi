<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Models\Company;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * «Επανυπολογισμός υπολοίπων» — the last button of the retired «Εργαλεία» page,
 * rehomed as a header action on the Παραστατικά list. It runs the same safe,
 * idempotent `invoices:recompute-balances` command scoped to the active tenant.
 */
class InvoiceRecomputeBalancesActionTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        Gate::before(fn () => true); // authorize list access + the View:CompanySettings gate
        $this->actingAs(User::create([
            'name' => 'Admin', 'email' => 'a-'.uniqid().'@test.local', 'password' => bcrypt('x'),
        ]));
        $this->tenant = Company::create([
            'name' => 'Tools OE', 'slug' => 'tools-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
        Filament::setTenant($this->tenant);
    }

    public function test_list_exposes_the_recompute_balances_action(): void
    {
        Livewire::test(ListInvoices::class)
            ->assertSuccessful()
            ->assertActionVisible('recompute_balances');
    }

    public function test_recompute_balances_action_runs_without_errors(): void
    {
        Livewire::test(ListInvoices::class)
            ->callAction('recompute_balances')
            ->assertHasNoActionErrors()
            ->assertNotified();
    }
}
