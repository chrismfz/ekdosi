<?php

namespace Tests\Feature\Filament;

use App\Filament\Widgets\ProviderQuotaStats;
use App\Models\Company;
use App\Models\MyDataMark;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * PROV-009: the «Πάροχος ΥΠΑΗΕΣ» dashboard widget — the running provider quota
 * read off the latest provider mark's remaining_invoices, colour-banded by the
 * low-quota threshold. Provider tenants only.
 */
class ProviderQuotaStatsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Filament::setTenant() fires TenantSet($tenant, $user) — needs an auth user.
        $this->actingAs(User::create([
            'name' => 'Op', 'email' => 'op-'.uniqid().'@t.local', 'password' => bcrypt('x'),
        ]));
    }

    private function providerTenant(): Company
    {
        return Company::create([
            'name' => 'Prov', 'slug' => 'prov-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-provider', 'einvoice_provider_key' => 'invosign',
            'einvoice_provider_mode' => 'sandbox',
        ]);
    }

    private function mark(Company $c, ?int $remaining): void
    {
        MyDataMark::create([
            'company_id' => $c->id, 'mark' => (string) random_int(1, 999999999),
            'mydata_action' => 'PROVIDER_INSERT', 'provider_key' => 'invosign',
            'remaining_invoices' => $remaining,
            'mark_date' => now()->toDateString(), 'mark_time' => now()->toTimeString(),
        ]);
    }

    public function test_visible_only_for_provider_tenants(): void
    {
        Filament::setTenant($this->providerTenant());
        $this->assertTrue(ProviderQuotaStats::canView());

        Filament::setTenant(Company::create([
            'name' => 'MD', 'slug' => 'md-'.uniqid(), 'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata',
        ]));
        $this->assertFalse(ProviderQuotaStats::canView());
    }

    public function test_shows_the_latest_quota_reading(): void
    {
        $c = $this->providerTenant();
        $this->mark($c, 500);   // older
        $this->mark($c, 988);   // latest
        Filament::setTenant($c);

        Livewire::test(ProviderQuotaStats::class)
            ->assertSee('988')
            ->assertSee('Επαρκές υπόλοιπο')
            ->assertDontSee('500');
    }

    public function test_low_quota_reads_as_a_warning_band(): void
    {
        $c = $this->providerTenant();
        $this->mark($c, 7);     // ≤ default threshold (50)
        Filament::setTenant($c);

        Livewire::test(ProviderQuotaStats::class)
            ->assertSee('7')
            ->assertSee('Χαμηλό');
    }

    public function test_placeholder_before_the_first_provider_filing(): void
    {
        $c = $this->providerTenant();
        Filament::setTenant($c);

        Livewire::test(ProviderQuotaStats::class)
            ->assertSee('Καμία υποβολή μέσω παρόχου ακόμη');
    }
}
