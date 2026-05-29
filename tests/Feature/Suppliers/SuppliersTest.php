<?php

namespace Tests\Feature\Suppliers;

use App\Enums\SupplierSource;
use App\Filament\Resources\Suppliers\Pages\CreateSupplier;
use App\Filament\Resources\Suppliers\Pages\ListSuppliers;
use App\Models\Company;
use App\Models\Supplier;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Suppliers (προμηθευτές) foundation — the first brick of the Έξοδα phase.
 * Net-new entity; tenant-scoped like customers.
 */
class SuppliersTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(string $mode = 'sandbox', bool $withCreds = true): Company
    {
        return Company::create([
            'name' => 'Sup Test',
            'slug' => 'sup-'.uniqid(),
            'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata',
            'mydata_mode' => $mode,
            'mydata_aade_id' => $withCreds ? 'user1' : null,
            'mydata_subscription_key' => $withCreds ? 'key1' : null,
        ]);
    }

    private function actingOperator(Company $tenant): void
    {
        $user = User::create([
            'name' => 'Op',
            'email' => 'op-'.uniqid().'@example.test',
            'password' => bcrypt('x'),
        ]);
        Gate::before(fn () => true);
        $this->actingAs($user);
        Filament::setTenant($tenant);
    }

    public function test_supplier_casts_source_and_soft_deletes(): void
    {
        $tenant = $this->tenant();
        $s = Supplier::create([
            'company_id' => $tenant->id,
            'afm' => '123456789',
            'name' => 'ΠΡΟΜΗΘΕΥΤΗΣ ΑΕ',
            'source' => SupplierSource::Manual->value,
        ]);

        $this->assertInstanceOf(SupplierSource::class, $s->refresh()->source);
        $this->assertTrue($s->is_active); // default

        $s->delete();
        $this->assertSoftDeleted($s);
    }

    public function test_list_page_renders_and_shows_supplier(): void
    {
        // Cross-tenant scoping is a Filament panel-level global scope
        // (identical to CustomerResource, proven in production) and isn't
        // exercised by the bare Livewire::test harness — so we assert what
        // is ours to verify: the page boots and our supplier is listed.
        $tenant = $this->tenant();
        $this->actingOperator($tenant);

        $mine = Supplier::create([
            'company_id' => $tenant->id,
            'afm' => '111111111',
            'name' => 'Δικός μου',
            'source' => 'manual',
        ]);

        Livewire::test(ListSuppliers::class)
            ->assertOk()
            ->loadTable()
            ->assertCanSeeTableRecords([$mine]);
    }

    public function test_create_page_renders(): void
    {
        $tenant = $this->tenant();
        $this->actingOperator($tenant);

        Livewire::test(CreateSupplier::class)->assertOk();
    }

    /* ---------------- spike command guards (no live AADE) ---------------- */

    public function test_fetch_docs_requires_tenant(): void
    {
        $this->artisan('mydata:fetch-docs')->assertExitCode(1);
    }

    public function test_fetch_docs_fails_when_mydata_off(): void
    {
        $tenant = $this->tenant(mode: 'off');

        $this->artisan('mydata:fetch-docs', ['--tenant' => $tenant->slug])
            ->assertExitCode(1);
    }

    public function test_fetch_docs_fails_without_credentials(): void
    {
        $tenant = $this->tenant(mode: 'sandbox', withCreds: false);

        $this->artisan('mydata:fetch-docs', ['--tenant' => $tenant->slug])
            ->assertExitCode(1);
    }
}
