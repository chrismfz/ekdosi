<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\FirebirdImportRuns\Pages\CreateFirebirdImportRun;
use App\Models\Company;
use App\Models\FirebirdImportRun;
use App\Models\User;
use App\Services\MyData\MyDataLookupSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class EpsilonUploadSubmitTest extends TestCase
{
    use RefreshDatabase;

    public function test_uploading_epsilon_json_routes_to_epsilon_import(): void
    {
        Storage::fake('local');
        Gate::before(fn () => true);

        $this->actingAs(User::create([
            'name' => 'Op', 'email' => 'op-'.uniqid().'@test.local', 'password' => bcrypt('x'),
        ]));

        $tenant = Company::create([
            'name' => 'T', 'slug' => 't-'.uniqid(),
            'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
        Filament::setTenant($tenant);

        $seeder = app(MyDataLookupSeeder::class);
        $seeder->seedVatCategories($tenant);
        $seeder->seedPaymentMethods($tenant);
        $seeder->seedMetricUnits($tenant);
        $seeder->seedProductCategories($tenant);
        $seeder->seedInvoiceTypes($tenant);

        $customers = UploadedFile::fake()->createWithContent(
            'DataExport-Customers.json',
            (string) file_get_contents(base_path('docs/smart-epsilon-export/DataExport-Customers.json')),
        );

        Livewire::test(CreateFirebirdImportRun::class)
            ->set('data.customers_json', [$customers])
            ->call('create')
            ->assertHasNoErrors();

        $run = FirebirdImportRun::where('company_id', $tenant->id)->first();
        $this->assertNotNull($run, 'No import run was created.');
        $this->assertSame(FirebirdImportRun::SOURCE_EPSILON, $run->source, 'Run was not recorded as Epsilon source.');
        $this->assertSame(FirebirdImportRun::STATUS_COMPLETED, $run->status);
    }

    /**
     * The reported prod bug: validation passed because the Epsilon files were
     * present when it ran, but FileUpload dropped the vanished temp files during
     * `saveUploadedFiles()`, so `handleRecordCreation` is reached with EVERY
     * field empty. It must halt (friendly notification, no run) instead of
     * falling through to `Storage::disk('local')->path(null)` — the opaque
     * flysystem TypeError 500 the operator hit.
     */
    public function test_empty_data_after_validation_halts_without_opaque_500(): void
    {
        Storage::fake('local');
        Gate::before(fn () => true);

        $this->actingAs(User::create([
            'name' => 'Op', 'email' => 'op-'.uniqid().'@test.local', 'password' => bcrypt('x'),
        ]));

        $tenant = Company::create([
            'name' => 'T', 'slug' => 't-'.uniqid(),
            'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
        Filament::setTenant($tenant);

        $page = new CreateFirebirdImportRun;
        $handle = (new \ReflectionMethod($page, 'handleRecordCreation'))->getClosure($page);

        // Empty file fields (what FileUpload dehydrates a dropped upload to).
        $this->expectException(\Filament\Support\Exceptions\Halt::class);

        try {
            $handle([
                'upload' => null, 'customers_json' => null, 'items_json' => null,
                'services_json' => null, 'sales_json' => null,
                'fb_host' => '127.0.0.1', 'fb_user' => 'SYSDBA',
            ]);
        } finally {
            $this->assertSame(0, FirebirdImportRun::where('company_id', $tenant->id)->count());
        }
    }
}
