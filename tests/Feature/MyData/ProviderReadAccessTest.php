<?php

namespace Tests\Feature\MyData;

use App\Enums\MyDataMode;
use App\Filament\Pages\MyDataConsole;
use App\Filament\Pages\MyDataConsoleExpenses;
use App\Filament\Pages\MyDataE3Overview;
use App\Filament\Widgets\MyDataPictureStats;
use App\Models\Company;
use App\Models\User;
use App\Services\MyData\FirebedCredentials;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use RuntimeException;
use Tests\TestCase;

/**
 * A `gr-provider` tenant files through a certified ΥΠΑΗΕΣ provider, but its
 * documents still land at AADE under its own ΑΦΜ — so it keeps READING the
 * myDATA picture / consoles / Ε3 with its own myDATA read subscription. These
 * read-side surfaces must therefore stay visible for a provider that has read
 * credentials, while SUBMISSION stays gr-mydata-only.
 *
 * Regression for the "switched MyIP to πάροχος → lost the Εικόνα από myDATA"
 * report.
 */
class ProviderReadAccessTest extends TestCase
{
    use RefreshDatabase;

    private function company(array $attrs): Company
    {
        return Company::create(array_merge([
            'name' => 'T', 'slug' => 't-'.uniqid(), 'country_code' => 'GR',
        ], $attrs));
    }

    public function test_mydata_read_mode_for_direct_mydata_tenant(): void
    {
        $sandbox = $this->company(['einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'sandbox']);
        $prod = $this->company(['einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'production']);
        $off = $this->company(['einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off']);

        $this->assertSame(MyDataMode::Sandbox, $sandbox->mydataReadMode());
        $this->assertSame(MyDataMode::Production, $prod->mydataReadMode());
        $this->assertNull($off->mydataReadMode());

        $this->assertTrue($sandbox->canReadMyData());
        $this->assertTrue($prod->canReadMyData());
        $this->assertFalse($off->canReadMyData());
    }

    public function test_provider_with_read_credentials_can_read(): void
    {
        // A provider whose mydata_mode is 'off' (the channel form clears it) but
        // who kept its production read credentials reads from the production env.
        $prov = $this->company([
            'einvoice_provider' => 'gr-provider', 'einvoice_provider_key' => 'invosign',
            'einvoice_provider_mode' => 'production', 'mydata_mode' => 'off',
            'mydata_aade_id_production' => 'PRODUSER',
            'mydata_subscription_key_production' => 'PRODKEY',
        ]);

        $this->assertSame(MyDataMode::Production, $prov->mydataReadMode());
        $this->assertTrue($prov->canReadMyData());
    }

    public function test_provider_read_env_follows_provider_mode_not_slot_preference(): void
    {
        // BOTH credential slots populated. The read environment must follow
        // einvoice_provider_mode (the provider stack's sandbox/production twin),
        // NOT blindly prefer production — otherwise a sandbox-testing provider
        // would read LIVE production data while it submits to sandbox.
        $sandboxProvider = $this->company([
            'einvoice_provider' => 'gr-provider', 'einvoice_provider_key' => 'invosign',
            'einvoice_provider_mode' => 'sandbox', 'mydata_mode' => 'off',
            'mydata_aade_id_sandbox' => 'SBX', 'mydata_subscription_key_sandbox' => 'SK',
            'mydata_aade_id_production' => 'PROD', 'mydata_subscription_key_production' => 'PK',
        ]);
        $this->assertSame(MyDataMode::Sandbox, $sandboxProvider->mydataReadMode());

        $prodProvider = $this->company([
            'einvoice_provider' => 'gr-provider', 'einvoice_provider_key' => 'invosign',
            'einvoice_provider_mode' => 'production', 'mydata_mode' => 'off',
            'mydata_aade_id_sandbox' => 'SBX', 'mydata_subscription_key_sandbox' => 'SK',
            'mydata_aade_id_production' => 'PROD', 'mydata_subscription_key_production' => 'PK',
        ]);
        $this->assertSame(MyDataMode::Production, $prodProvider->mydataReadMode());
    }

    public function test_provider_falls_back_to_other_slot_when_preferred_is_empty(): void
    {
        // Mid-migration: provider set to sandbox but only its OLD production read
        // subscription survives. A read never writes to AADE, so we fall back to
        // the populated slot rather than hide the picture entirely.
        $prov = $this->company([
            'einvoice_provider' => 'gr-provider', 'einvoice_provider_key' => 'invosign',
            'einvoice_provider_mode' => 'sandbox', 'mydata_mode' => 'off',
            'mydata_aade_id_production' => 'PROD', 'mydata_subscription_key_production' => 'PK',
        ]);
        $this->assertSame(MyDataMode::Production, $prov->mydataReadMode());
        $this->assertTrue($prov->canReadMyData());
    }

    public function test_provider_with_only_sandbox_credentials_reads_sandbox(): void
    {
        $prov = $this->company([
            'einvoice_provider' => 'gr-provider', 'einvoice_provider_key' => 'invosign',
            'einvoice_provider_mode' => 'sandbox', 'mydata_mode' => 'off',
            'mydata_aade_id_sandbox' => 'SBXUSER',
            'mydata_subscription_key_sandbox' => 'SBXKEY',
        ]);

        $this->assertSame(MyDataMode::Sandbox, $prov->mydataReadMode());
        $this->assertTrue($prov->canReadMyData());
    }

    public function test_provider_without_read_credentials_cannot_read(): void
    {
        $prov = $this->company([
            'einvoice_provider' => 'gr-provider', 'einvoice_provider_key' => 'invosign',
            'einvoice_provider_mode' => 'sandbox', 'mydata_mode' => 'off',
        ]);

        $this->assertNull($prov->mydataReadMode());
        $this->assertFalse($prov->canReadMyData());
    }

    public function test_non_greek_channels_cannot_read(): void
    {
        $this->assertFalse($this->company(['einvoice_provider' => 'none'])->canReadMyData());
        $this->assertFalse($this->company(['einvoice_provider' => 'ee-peppol'])->canReadMyData());
    }

    public function test_read_env_override_lets_a_sandbox_provider_read_production(): void
    {
        // THE headline case: a provider submitting via InvoSign Δοκιμαστικό (sandbox)
        // that wants to READ its real production myDATA. Auto would resolve Sandbox;
        // the explicit override flips reads to Production while submission stays dev.
        $prov = $this->company([
            'einvoice_provider' => 'gr-provider', 'einvoice_provider_key' => 'invosign',
            'einvoice_provider_mode' => 'sandbox', 'mydata_mode' => 'off',
            'mydata_read_env' => 'production',
            'mydata_aade_id_sandbox' => 'SBX', 'mydata_subscription_key_sandbox' => 'SK',
            'mydata_aade_id_production' => 'PROD', 'mydata_subscription_key_production' => 'PK',
        ]);

        $this->assertSame(MyDataMode::Production, $prov->mydataReadMode());
        $this->assertTrue($prov->canReadMyData());
    }

    public function test_read_env_override_is_ignored_when_target_slot_is_empty(): void
    {
        // Override asks for production, but no production read credentials exist →
        // fall through to auto (sandbox) rather than silently reading nothing.
        $prov = $this->company([
            'einvoice_provider' => 'gr-provider', 'einvoice_provider_key' => 'invosign',
            'einvoice_provider_mode' => 'sandbox', 'mydata_mode' => 'off',
            'mydata_read_env' => 'production',
            'mydata_aade_id_sandbox' => 'SBX', 'mydata_subscription_key_sandbox' => 'SK',
        ]);

        $this->assertSame(MyDataMode::Sandbox, $prov->mydataReadMode());
        $this->assertTrue($prov->canReadMyData());
    }

    public function test_read_env_override_flips_a_direct_mydata_tenant_to_sandbox(): void
    {
        // A gr-mydata tenant submitting to Production but reading Sandbox on purpose.
        $t = $this->company([
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'production',
            'mydata_read_env' => 'sandbox',
            'mydata_aade_id_sandbox' => 'SBX', 'mydata_subscription_key_sandbox' => 'SK',
            'mydata_aade_id_production' => 'PROD', 'mydata_subscription_key_production' => 'PK',
        ]);

        $this->assertSame(MyDataMode::Sandbox, $t->mydataReadMode());
    }

    public function test_null_read_env_override_keeps_the_automatic_behaviour(): void
    {
        // Explicit null (the default) must be identical to the pre-override auto rule.
        $prov = $this->company([
            'einvoice_provider' => 'gr-provider', 'einvoice_provider_key' => 'invosign',
            'einvoice_provider_mode' => 'sandbox', 'mydata_mode' => 'off',
            'mydata_read_env' => null,
            'mydata_aade_id_sandbox' => 'SBX', 'mydata_subscription_key_sandbox' => 'SK',
            'mydata_aade_id_production' => 'PROD', 'mydata_subscription_key_production' => 'PK',
        ]);

        $this->assertSame(MyDataMode::Sandbox, $prov->mydataReadMode());
    }

    public function test_firebed_credentials_init_honours_the_read_env_override(): void
    {
        // Override → production, sandbox is the auto default: init must resolve the
        // production environment (no throw) because the override wins.
        $prov = $this->company([
            'einvoice_provider' => 'gr-provider', 'einvoice_provider_key' => 'invosign',
            'einvoice_provider_mode' => 'sandbox', 'mydata_mode' => 'off',
            'mydata_read_env' => 'production',
            'mydata_aade_id_production' => 'PRODUSER',
            'mydata_subscription_key_production' => 'PRODKEY',
        ]);

        FirebedCredentials::init($prov, null);
        $this->assertSame(MyDataMode::Production, $prov->mydataReadMode());
    }

    public function test_firebed_credentials_init_picks_provider_read_environment(): void
    {
        // Provider with production read creds → must NOT throw (read is allowed)
        // and must resolve the production environment, not the empty sandbox slot.
        $prov = $this->company([
            'einvoice_provider' => 'gr-provider', 'einvoice_provider_key' => 'invosign',
            'einvoice_provider_mode' => 'production', 'mydata_mode' => 'off',
            'mydata_aade_id_production' => 'PRODUSER',
            'mydata_subscription_key_production' => 'PRODKEY',
        ]);

        // No exception = the provider read gate + env resolution both pass.
        FirebedCredentials::init($prov, null);
        $this->assertTrue(true);
    }

    public function test_firebed_credentials_init_rejects_provider_without_credentials(): void
    {
        $prov = $this->company([
            'einvoice_provider' => 'gr-provider', 'einvoice_provider_key' => 'invosign',
            'einvoice_provider_mode' => 'sandbox', 'mydata_mode' => 'off',
        ]);

        $this->expectException(RuntimeException::class);
        FirebedCredentials::init($prov, null);
    }

    public function test_dashboard_picture_widget_visible_for_provider_with_credentials(): void
    {
        Gate::before(fn () => true);
        $this->actingAs(User::create([
            'name' => 'Admin', 'email' => 'a-'.uniqid().'@test.local', 'password' => bcrypt('x'),
        ]));

        $prov = $this->company([
            'einvoice_provider' => 'gr-provider', 'einvoice_provider_key' => 'invosign',
            'einvoice_provider_mode' => 'production', 'mydata_mode' => 'off',
            'mydata_aade_id_production' => 'PRODUSER',
            'mydata_subscription_key_production' => 'PRODKEY',
        ]);
        Filament::setTenant($prov);

        $this->assertTrue(MyDataPictureStats::canView());
        $this->assertTrue(MyDataConsole::canAccess());
        $this->assertTrue(MyDataConsoleExpenses::canAccess());
        $this->assertTrue(MyDataE3Overview::canAccess());
    }

    public function test_dashboard_picture_widget_hidden_for_provider_without_credentials(): void
    {
        $this->actingAs(User::create([
            'name' => 'Admin', 'email' => 'a-'.uniqid().'@test.local', 'password' => bcrypt('x'),
        ]));

        $prov = $this->company([
            'einvoice_provider' => 'gr-provider', 'einvoice_provider_key' => 'invosign',
            'einvoice_provider_mode' => 'sandbox', 'mydata_mode' => 'off',
        ]);
        Filament::setTenant($prov);

        $this->assertFalse(MyDataPictureStats::canView());
    }
}
