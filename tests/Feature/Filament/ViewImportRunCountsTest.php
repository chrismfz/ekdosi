<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\FirebirdImportRuns\Pages\ViewFirebirdImportRun;
use App\Models\Company;
use App\Models\FirebirdImportRun;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The View page renders `counts_json` in TWO shapes: the Firebird ETL writes a
 * flat `table => int`; the Epsilon importer writes a nested
 * `entity => ['created','updated','skipped']`. The infolist must render both —
 * the nested shape previously crashed `number_format(array)` (500 on the View
 * page right after a successful Epsilon import).
 */
class ViewImportRunCountsTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(): Company
    {
        Gate::before(fn () => true);
        $this->actingAs(User::create([
            'name' => 'Op', 'email' => 'op-'.uniqid().'@test.local', 'password' => bcrypt('x'),
        ]));
        $tenant = Company::create([
            'name' => 'T', 'slug' => 't-'.uniqid(),
            'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
        Filament::setTenant($tenant);

        return $tenant;
    }

    public function test_view_renders_nested_epsilon_counts(): void
    {
        $tenant = $this->tenant();

        $run = FirebirdImportRun::create([
            'company_id' => $tenant->id,
            'source'     => FirebirdImportRun::SOURCE_EPSILON,
            'file_name'  => 'Epsilon: items', 'file_size' => 0, 'file_sha256' => str_repeat('a', 64),
            'status'     => FirebirdImportRun::STATUS_COMPLETED,
            'counts_json' => ['products' => ['created' => 401, 'updated' => 2, 'skipped' => 0]],
        ]);

        Livewire::test(ViewFirebirdImportRun::class, ['record' => $run->getKey()])
            ->assertOk();
    }

    public function test_view_renders_flat_firebird_counts(): void
    {
        $tenant = $this->tenant();

        $run = FirebirdImportRun::create([
            'company_id' => $tenant->id,
            'source'     => FirebirdImportRun::SOURCE_FIREBIRD,
            'file_name'  => 'ekdosi.fbk', 'file_size' => 0, 'file_sha256' => str_repeat('a', 64),
            'status'     => FirebirdImportRun::STATUS_COMPLETED,
            'counts_json' => ['customers' => 91, 'products' => 1200],
        ]);

        Livewire::test(ViewFirebirdImportRun::class, ['record' => $run->getKey()])
            ->assertOk();
    }
}
