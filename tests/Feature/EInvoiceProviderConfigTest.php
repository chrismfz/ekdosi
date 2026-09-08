<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Support\EInvoice\ProviderCredentials;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * P1: the per-tenant provider columns persist, the config blob is encrypted at
 * rest (and round-trips as an array), and ProviderCredentials::fromCompany maps
 * the tenant config + mode correctly. No behaviour change to the myDATA path.
 */
class EInvoiceProviderConfigTest extends TestCase
{
    use RefreshDatabase;

    public function test_provider_config_round_trips_as_array_and_is_encrypted_at_rest(): void
    {
        // Encryption-at-rest is now opt-in (DR default = plaintext); turn it on
        // to exercise the encrypted path this test is about.
        config(['ekdosi.secrets.encrypt_at_rest' => true]);
        $company = Company::create([
            'name' => 't', 'slug' => 't-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-provider',
            'einvoice_provider_key' => 'invosign',
            'einvoice_provider_mode' => 'sandbox',
            'einvoice_provider_config' => ['token' => 'secret-token', 'base_url' => 'https://demo/api'],
        ]);

        // Round-trips as an array via the encrypted:array cast.
        $fresh = Company::find($company->id);
        $this->assertSame('invosign', $fresh->einvoice_provider_key);
        $this->assertSame('sandbox', $fresh->einvoice_provider_mode);
        $this->assertSame('secret-token', $fresh->einvoice_provider_config['token']);
        $this->assertSame('https://demo/api', $fresh->einvoice_provider_config['base_url']);

        // Stored ciphertext must NOT contain the plaintext secret.
        $raw = DB::table('companies')->where('id', $company->id)->value('einvoice_provider_config');
        $this->assertIsString($raw);
        $this->assertStringNotContainsString('secret-token', $raw);
    }

    public function test_credentials_from_company_maps_config_and_sandbox_flag(): void
    {
        $sandbox = Company::create([
            'name' => 's', 'slug' => 's-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider_mode' => 'sandbox',
            'einvoice_provider_config' => ['api_key' => 'k1'],
        ]);
        $prod = Company::create([
            'name' => 'p', 'slug' => 'p-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider_mode' => 'production',
            'einvoice_provider_config' => ['api_key' => 'k2'],
        ]);

        $cs = ProviderCredentials::fromCompany($sandbox->fresh());
        $this->assertTrue($cs->sandbox);
        $this->assertSame('k1', $cs->get('api_key'));
        $this->assertTrue($cs->has('api_key'));
        $this->assertNull($cs->get('missing'));

        $cp = ProviderCredentials::fromCompany($prod->fresh());
        $this->assertFalse($cp->sandbox);
        $this->assertSame('k2', $cp->get('api_key'));
    }

    public function test_mode_defaults_to_off_and_empty_config_is_safe(): void
    {
        $company = Company::create([
            'name' => 'd', 'slug' => 'd-'.uniqid(), 'country_code' => 'GR',
        ]);

        $this->assertSame('off', $company->fresh()->einvoice_provider_mode);

        $creds = ProviderCredentials::fromCompany($company->fresh());
        $this->assertSame([], $creds->config);
        $this->assertTrue($creds->sandbox); // off ≠ production → sandbox-safe
    }
}
