<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\MyDataConfigCheck;
use App\Models\Company;
use App\Models\InvoiceType;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The «Έλεγχος ρυθμίσεων» console tab — structured view over MyDataConfigAudit.
 */
class MyDataConfigCheckPageTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        Gate::before(fn () => true); // authorize View:MyDataConfigCheck
        $this->actingAs(User::create([
            'name' => 'Admin', 'email' => 'a-'.uniqid().'@test.local', 'password' => bcrypt('x'),
        ]));
        $this->tenant = Company::create([
            'name' => 'Cfg OE', 'slug' => 'cfg-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'sandbox',
            'afm' => '800561849',
            'mydata_aade_id_sandbox' => 'TESTUSER',
            'mydata_subscription_key_sandbox' => 'TESTKEY',
        ]);
        Filament::setTenant($this->tenant);
    }

    public function test_page_renders_and_surfaces_audit_counts(): void
    {
        InvoiceType::create([
            'company_id' => $this->tenant->id, 'code' => 'BAD', 'name' => 'Λάθος',
            'invcount' => 1, 'mydata_type' => '99.9',
        ]);

        Livewire::test(MyDataConfigCheck::class)
            ->assertSuccessful()
            ->assertSet('clean', false)
            ->assertSet('errorCount', 1);
    }

    public function test_recheck_action_runs(): void
    {
        Livewire::test(MyDataConfigCheck::class)
            ->callAction('recheck')
            ->assertHasNoActionErrors();
    }

    public function test_hidden_for_non_gr_mydata_tenant(): void
    {
        $ee = Company::create([
            'name' => 'EE OU', 'slug' => 'ee-'.uniqid(), 'country_code' => 'EE',
            'einvoice_provider' => 'ee-peppol', 'mydata_mode' => 'off',
        ]);
        Filament::setTenant($ee);

        $this->assertFalse(MyDataConfigCheck::canAccess());
    }
}
