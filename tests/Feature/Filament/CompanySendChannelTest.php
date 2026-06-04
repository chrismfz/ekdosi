<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Companies\Pages\CreateCompany;
use App\Filament\Resources\Companies\Pages\EditCompany;
use App\Models\Company;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * P3 end-to-end: the flat «Τρόπος αποστολής» dropdown on the Company page decomposes
 * into the real columns + encrypted provider config through the page hooks, and the
 * edit form re-hydrates the channel from the record.
 */
class CompanySendChannelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Gate::before(fn () => true);
        $this->actingAs(User::create([
            'name' => 'Admin', 'email' => 'a-'.uniqid().'@test.local', 'password' => bcrypt('x'),
        ]));
        Filament::setTenant(Company::create([
            'name' => 'Host', 'slug' => 'host-'.uniqid(),
            'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]));
    }

    public function test_provider_channel_decomposes_into_columns_and_encrypted_config(): void
    {
        $slug = 'prov-'.uniqid();

        Livewire::test(CreateCompany::class)
            ->fillForm([
                'name' => 'Πάροχος ΑΕ',
                'slug' => $slug,
                'country_code' => 'GR',
                'send_channel' => 'invosign-production',
                'cfg_invosign_base_url' => 'https://api.invosign/x',
                'cfg_invosign_token' => 'tok-secret',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $company = Company::query()->withoutGlobalScopes()->where('slug', $slug)->firstOrFail();

        $this->assertSame('gr-provider', $company->einvoice_provider);
        $this->assertSame('invosign', $company->einvoice_provider_key);
        $this->assertSame('production', $company->einvoice_provider_mode);
        $this->assertSame('off', $company->mydata_mode);
        $this->assertSame('tok-secret', $company->einvoice_provider_config['token']);

        // Encrypted at rest — the raw column must not contain the plaintext token.
        $raw = DB::table('companies')->where('id', $company->id)->value('einvoice_provider_config');
        $this->assertStringNotContainsString('tok-secret', (string) $raw);
    }

    public function test_mydata_channel_sets_mode_and_provider(): void
    {
        $slug = 'md-'.uniqid();

        Livewire::test(CreateCompany::class)
            ->fillForm([
                'name' => 'myDATA ΑΕ',
                'slug' => $slug,
                'country_code' => 'GR',
                'send_channel' => 'mydata-production',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $company = Company::query()->withoutGlobalScopes()->where('slug', $slug)->firstOrFail();
        $this->assertSame('gr-mydata', $company->einvoice_provider);
        $this->assertSame('production', $company->mydata_mode);
    }

    public function test_edit_form_rehydrates_channel_and_keeps_blank_secret(): void
    {
        $company = Company::create([
            'name' => 'Edit me', 'slug' => 'edit-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-provider', 'einvoice_provider_key' => 'invosign',
            'einvoice_provider_mode' => 'sandbox',
            'einvoice_provider_config' => ['base_url' => 'https://old', 'token' => 'KEEP-ME'],
        ]);

        Livewire::test(EditCompany::class, ['record' => $company->getRouteKey()])
            ->assertFormSet(['send_channel' => 'invosign-sandbox'])
            // change base_url, leave the (never-prefilled) secret blank → keep stored
            ->fillForm(['cfg_invosign_base_url' => 'https://new'])
            ->call('save')
            ->assertHasNoFormErrors();

        $fresh = $company->fresh();
        $this->assertSame('https://new', $fresh->einvoice_provider_config['base_url']);
        $this->assertSame('KEEP-ME', $fresh->einvoice_provider_config['token']);
    }
}
