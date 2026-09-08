<?php

namespace Tests\Feature\Whmcs;

use App\Exceptions\Whmcs\WhmcsNotConfigured;
use App\Models\Company;
use App\Services\Whmcs\WhmcsClient;
use App\Services\Whmcs\WhmcsClientFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WhmcsClientFactoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_factory_throws_when_tenant_has_no_url(): void
    {
        $tenant = Company::create([
            'name' => 'NoWhmcs',
            'slug' => 'no-whmcs-'.uniqid(),
            'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata',
            'mydata_mode' => 'off',
        ]);

        $this->expectException(WhmcsNotConfigured::class);
        app(WhmcsClientFactory::class)->for($tenant);
    }

    public function test_factory_throws_when_tenant_has_url_but_no_credentials(): void
    {
        // Half-configured tenant: url present, identifier/secret missing.
        // hasWhmcsIntegration() requires all three — factory should refuse.
        $tenant = Company::create([
            'name' => 'HalfWhmcs',
            'slug' => 'half-whmcs-'.uniqid(),
            'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata',
            'mydata_mode' => 'off',
            'whmcs_api_url' => 'https://example.gr/includes/api.php',
        ]);

        $this->expectException(WhmcsNotConfigured::class);
        app(WhmcsClientFactory::class)->for($tenant);
    }

    public function test_factory_returns_client_when_fully_configured(): void
    {
        $tenant = Company::create([
            'name' => 'OkWhmcs',
            'slug' => 'ok-whmcs-'.uniqid(),
            'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata',
            'mydata_mode' => 'off',
            'whmcs_api_url' => 'https://example.gr/includes/api.php',
            'whmcs_api_identifier' => 'TEST_ID',
            'whmcs_api_secret' => 'TEST_SECRET',  // auto-encrypted by cast
        ]);

        $client = app(WhmcsClientFactory::class)->for($tenant);

        $this->assertInstanceOf(WhmcsClient::class, $client);
    }

    public function test_secret_is_encrypted_at_rest(): void
    {
        // Encryption-at-rest is opt-in now (DR default = plaintext); enable it.
        config(['ekdosi.secrets.encrypt_at_rest' => true]);
        $tenant = Company::create([
            'name' => 'Encrypted',
            'slug' => 'enc-whmcs-'.uniqid(),
            'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata',
            'mydata_mode' => 'off',
            'whmcs_api_url' => 'https://example.gr/includes/api.php',
            'whmcs_api_identifier' => 'TEST_ID',
            'whmcs_api_secret' => 'plain-text-secret',
        ]);

        // Raw DB row should NOT contain the plaintext secret.
        $raw = \DB::table('companies')->where('id', $tenant->id)->value('whmcs_api_secret');
        $this->assertNotSame('plain-text-secret', $raw,
            'Secret stored in DB as plaintext — cast not applied.');

        // Eloquent accessor decrypts back to plaintext.
        $this->assertSame('plain-text-secret', $tenant->fresh()->whmcs_api_secret);
    }
}
