<?php

namespace Tests\Feature;

use App\Filament\Support\VatRateOptions;
use App\Models\Company;
use App\Models\User;
use App\Models\VatCategory;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VatRateOptionsTest extends TestCase
{
    use RefreshDatabase;

    private function actAsOperator(): void
    {
        $user = User::create([
            'name' => 'Op', 'email' => 'op-'.uniqid().'@example.test', 'password' => bcrypt('x'),
        ]);
        $this->actingAs($user);
    }

    public function test_normalize_is_two_decimal_string(): void
    {
        $this->assertSame('24.00', VatRateOptions::normalize(24));
        $this->assertSame('24.00', VatRateOptions::normalize(24.0));
        $this->assertSame('24.00', VatRateOptions::normalize('24'));
        $this->assertSame('0.00', VatRateOptions::normalize(null));
        $this->assertSame('13.00', VatRateOptions::normalize(13.004));
    }

    public function test_options_come_from_tenant_categories_keyed_by_normalized_rate(): void
    {
        $tenant = Company::factory()->create();
        VatCategory::create(['company_id' => $tenant->id, 'description' => 'Κανονικός', 'rate' => 24]);
        VatCategory::create(['company_id' => $tenant->id, 'description' => 'Μειωμένος', 'rate' => 13]);
        VatCategory::create(['company_id' => $tenant->id, 'description' => 'Απαλλαγή', 'rate' => 0, 'vat_exemption_category' => 16]);

        $this->actAsOperator();
        Filament::setTenant($tenant);
        $opts = VatRateOptions::options();

        // Keyed by the same 2dp string a $set('vat_percent', …) produces.
        $this->assertArrayHasKey('24.00', $opts);
        $this->assertArrayHasKey('13.00', $opts);
        $this->assertArrayHasKey('0.00', $opts);
        $this->assertStringContainsString('Κανονικός', $opts['24.00']);
        $this->assertStringContainsString('24%', $opts['24.00']);
        // Ordered by rate ascending.
        $this->assertSame(['0.00', '13.00', '24.00'], array_keys($opts));
    }

    public function test_falls_back_to_aade_rates_when_no_categories(): void
    {
        $tenant = Company::factory()->create();
        $this->actAsOperator();
        Filament::setTenant($tenant);

        $opts = VatRateOptions::options();

        // The AADE-valid Greek set, normalised + sorted by the helper's order.
        $this->assertArrayHasKey('24.00', $opts);
        $this->assertArrayHasKey('0.00', $opts);
        $this->assertArrayHasKey('4.00', $opts);
        $this->assertSame('24%', $opts['24.00']);   // no description in fallback
    }
}
