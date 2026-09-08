<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\FirebirdImportRuns\Pages\CreateFirebirdImportRun;
use App\Models\Company;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Smoke test that the «Data Import» create page boots with the Firebird +
 * Epsilon tabs (the Tabs nesting is the only render-risk in the change).
 */
class DataImportFormRendersTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_data_import_page_renders_with_both_tabs(): void
    {
        Gate::before(fn () => true);
        $this->actingAs(User::create([
            'name' => 'Op', 'email' => 'op-'.uniqid().'@test.local', 'password' => bcrypt('x'),
        ]));
        Filament::setTenant(Company::create([
            'name' => 'T', 'slug' => 't-'.uniqid(),
            'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]));

        Livewire::test(CreateFirebirdImportRun::class)->assertOk();
    }
}
