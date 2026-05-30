<?php

namespace Tests\Feature\MyData;

use App\Filament\Pages\MyDataE3Overview;
use App\Models\Company;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The Ε3 overview splits AADE's Ε3 aggregation into income (E3_56x) vs
 * expense (E3_58x) blocks with separate subtotals — summing both into one
 * "Σύνολο αξίας" was meaningless. These tests inject a serialized result (the
 * shape MyDataE3Overview::serialize produces) rather than calling AADE.
 */
class MyDataE3OverviewTest extends TestCase
{
    use RefreshDatabase;

    private function bootTenantUser(): Company
    {
        $tenant = Company::create([
            'name' => 'E3 Test',
            'slug' => 'e3-'.uniqid(),
            'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata',
            'mydata_mode' => 'sandbox',
        ]);

        $user = User::create([
            'name' => 'Op',
            'email' => 'op-'.uniqid().'@example.test',
            'password' => bcrypt('x'),
        ]);

        Gate::before(fn () => true);
        $this->actingAs($user);
        Filament::setTenant($tenant);

        return $tenant;
    }

    /** @return array<string, mixed> */
    private function fakeResult(): array
    {
        return [
            'from' => '01/04/2026', 'to' => '30/04/2026', 'docCount' => 3,
            'total' => 5471.43, 'incomeTotal' => 100.0, 'expenseTotal' => 5371.43, 'unknownTotal' => 0.0,
            'rows' => [
                [
                    'classType' => 'E3_561_001', 'classCategory' => 'category1_3',
                    'typeLabel' => 'Πωλήσεις', 'categoryLabel' => 'Έσοδα από Παροχή Υπηρεσιών',
                    'direction' => 'income', 'value' => 100.0, 'count' => 1,
                ],
                [
                    'classType' => 'E3_581_001', 'classCategory' => 'category2_6',
                    'typeLabel' => 'Παροχές σε εργαζομένους', 'categoryLabel' => 'Αμοιβές προσωπικού',
                    'direction' => 'expense', 'value' => 5371.43, 'count' => 2,
                ],
            ],
        ];
    }

    public function test_a_cached_fetch_is_restored_on_mount(): void
    {
        $tenant = $this->bootTenantUser();

        // Simulate a prior fetch parked in the cache (the shape rememberFetch
        // writes); a fresh page mount should rehydrate it without re-calling AADE.
        \Illuminate\Support\Facades\Cache::put(
            'mydata-fetch:MyDataE3Overview:'.$tenant->getKey(),
            ['state' => ['result' => $this->fakeResult(), 'ran' => true], 'at' => now()->toIso8601String()],
            now()->addHour(),
        );

        Livewire::test(MyDataE3Overview::class)
            ->assertSet('ran', true)
            ->assertSee('Έσοδα')
            ->assertSee('E3_561_001')
            ->assertSee('Αποθηκευμένο αποτέλεσμα');
    }

    public function test_e3_overview_splits_income_and_expense_with_separate_subtotals(): void
    {
        $this->bootTenantUser();

        Livewire::test(MyDataE3Overview::class)
            ->set('ran', true)
            ->set('result', $this->fakeResult())
            ->assertSee('Έσοδα')
            ->assertSee('Έξοδα')
            ->assertSee('E3_561_001')   // income row in its block
            ->assertSee('E3_581_001')   // expense row in its block
            // Separate subtotals rendered (not one combined 5.471,43 headline).
            ->assertSee('100,00')
            ->assertSee('5.371,43');
    }
}
