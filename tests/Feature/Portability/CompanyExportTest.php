<?php

namespace Tests\Feature\Portability;

use App\Models\Company;
use App\Models\InvoiceType;
use App\Models\VatCategory;
use App\Services\Portability\BundleArchive;
use App\Services\Portability\CompanyExporter;
use App\Services\Portability\SecretsCodec;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class CompanyExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_passphrase_codec_round_trips_and_hides_plaintext(): void
    {
        $codec = new SecretsCodec;
        $secrets = ['key' => 'SUPER-SECRET', 'cfg' => ['a' => 1, 'b' => 'χ'], 'empty' => null];

        $sealed = $codec->seal($secrets, 'passphrase', 'p@ss');

        $this->assertSame('passphrase', $sealed['mode']);
        $this->assertArrayHasKey('salt', $sealed);
        // Ciphertext, not the plaintext.
        $this->assertNotSame('SUPER-SECRET', $sealed['values']['key']);
        $this->assertStringNotContainsString('SUPER-SECRET', (string) $sealed['values']['key']);

        $this->assertSame($secrets, $codec->open($sealed, 'p@ss'));
    }

    public function test_wrong_passphrase_fails_to_open(): void
    {
        $codec = new SecretsCodec;
        $sealed = $codec->seal(['k' => 'v'], 'passphrase', 'right');

        $this->expectException(\Throwable::class);
        $codec->open($sealed, 'wrong');
    }

    public function test_raw_mode_keeps_plaintext_and_round_trips(): void
    {
        $codec = new SecretsCodec;
        $secrets = ['k' => 'v', 'cfg' => ['x' => 1]];

        $sealed = $codec->seal($secrets, 'raw', null);

        $this->assertSame('raw', $sealed['mode']);
        $this->assertSame($secrets, $codec->open($sealed, null));
    }

    public function test_passphrase_required_when_missing(): void
    {
        $this->expectException(RuntimeException::class);
        (new SecretsCodec)->seal(['k' => 'v'], 'passphrase', null);
    }

    public function test_build_seals_secrets_and_captures_setup(): void
    {
        $company = Company::create([
            'name' => 'MyIP OE', 'slug' => 'exp-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'afm' => '800561849',
            'mydata_aade_id_production' => '800561849M',
            'mydata_subscription_key_production' => 'PRODKEY',
            'gsis_password' => 'gsis-pw',
        ]);
        VatCategory::create(['company_id' => $company->id, 'description' => '24%', 'rate' => 24, 'is_default' => true]);
        InvoiceType::create([
            'company_id' => $company->id, 'code' => 'TPY', 'name' => 'ΤΠΥ', 'invcount' => 5,
            'mydata_type' => '2.1', 'mydata_income_class' => 'E3_561_001',
        ]);

        $bundle = app(CompanyExporter::class)->build($company, 'passphrase', 'p@ss');

        // Secrets are NOT in the company payload (sealed separately).
        $this->assertArrayNotHasKey('mydata_subscription_key_production', $bundle['company']);
        $this->assertArrayNotHasKey('gsis_password', $bundle['company']);
        $this->assertArrayNotHasKey('id', $bundle['company']);
        // Non-secret settings survive.
        $this->assertSame('800561849M', $bundle['company']['mydata_aade_id_production']);

        // Sealed secrets re-open to the originals (incl the encrypted:array cfg slot).
        $opened = (new SecretsCodec)->open($bundle['secrets'], 'p@ss');
        $this->assertSame('PRODKEY', $opened['mydata_subscription_key_production']);
        $this->assertSame('gsis-pw', $opened['gsis_password']);

        // Setup captured: the invoice type with its myDATA mapping + the counter.
        $this->assertSame(1, $bundle['manifest']['counts']['invoice_types']);
        $this->assertSame(1, $bundle['manifest']['counts']['vat_categories']);
        $type = $bundle['setup']['invoice_types'][0];
        $this->assertSame('E3_561_001', $type['mydata_income_class']);
        $this->assertSame(5, $type['invcount']);
    }

    public function test_no_encrypted_column_leaks_into_company_payload(): void
    {
        $company = Company::create([
            'name' => 'Sec OE', 'slug' => 'sec-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'afm' => '800561849',
            'mydata_subscription_key_production' => 'K1',
            'mydata_subscription_key_sandbox' => 'K2',
            'gsis_password' => 'gp', 'mail_smtp_password' => 'mp',
            'whmcs_api_secret' => 'ws', 'whmcs_webhook_secret' => 'wb',
        ]);

        $bundle = app(CompanyExporter::class)->build($company, 'passphrase', 'p@ss');

        // EVERY secret-cast column must be sealed out of company.json — derived
        // from the casts so a future secret column can't silently leak. Detection
        // matches the exporter (MaybeEncrypted OR the legacy encrypted casts).
        $secret = 0;
        foreach ($company->getCasts() as $col => $cast) {
            if (\App\Casts\MaybeEncrypted::isSecretCast((string) $cast)) {
                $secret++;
                $this->assertArrayNotHasKey($col, $bundle['company'], "secret {$col} leaked into company.json");
            }
        }
        $this->assertGreaterThanOrEqual(7, $secret, 'expected the secret-cast columns to be present');
    }

    public function test_server_secrets_are_redacted_from_the_bundle(): void
    {
        $company = Company::create([
            'name' => 'Srv OE', 'slug' => 'srv-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'afm' => '800561849',
        ]);
        $group = \App\Models\ServerGroup::create([
            'company_id' => $company->id, 'name' => 'cPanel', 'module' => 'cpanel',
            'username' => 'reseller', 'secret_encrypted' => 'super-secret-token',
        ]);
        \App\Models\Server::create([
            'company_id' => $company->id, 'server_group_id' => $group->id, 'name' => 'Virgo',
            'hostname' => 'virgo.example.gr', 'secret_encrypted' => 'per-server-pw',
        ]);

        $bundle = app(CompanyExporter::class)->build($company, 'raw', null);

        // A secret must NEVER ride in a bundle (plaintext OR ciphertext) — redacted.
        $this->assertNull($bundle['setup']['server_groups'][0]['secret_encrypted']);
        $this->assertNull($bundle['setup']['servers'][0]['secret_encrypted']);
        // The rest of the row still travels (re-enter the secret on the target VM).
        $this->assertSame('reseller', $bundle['setup']['server_groups'][0]['username']);
        $this->assertSame('virgo.example.gr', $bundle['setup']['servers'][0]['hostname']);
    }

    public function test_command_writes_a_readable_zip(): void
    {
        $company = Company::create([
            'name' => 'Zip OE', 'slug' => 'zip-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'afm' => '800561849',
            'mydata_subscription_key_production' => 'PRODKEY',
        ]);
        VatCategory::create(['company_id' => $company->id, 'description' => '24%', 'rate' => 24, 'is_default' => true]);

        $out = storage_path('app/exports/test-'.uniqid().'.zip');

        $this->artisan('company:export', [
            '--tenant' => $company->slug,
            '--out' => $out,
            '--raw' => true,
            '--no-interaction' => true,
        ])->assertSuccessful();

        $this->assertFileExists($out);

        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($out) === true);
        $this->assertNotFalse($zip->locateName('manifest.json'));
        $this->assertNotFalse($zip->locateName('company.json'));
        $this->assertNotFalse($zip->locateName('secrets.json'));
        $this->assertNotFalse($zip->locateName('setup/vat_categories.json'));
        // RAW mode → the plaintext secret IS present (the documented danger).
        $secrets = json_decode((string) $zip->getFromName('secrets.json'), true);
        $this->assertSame('raw', $secrets['mode']);
        $zip->close();

        @unlink($out);
    }

    public function test_bundle_archive_round_trips(): void
    {
        $company = Company::create([
            'name' => 'RT OE', 'slug' => 'rt-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'afm' => '800561849',
            'mydata_subscription_key_production' => 'PRODKEY',
        ]);
        InvoiceType::create(['company_id' => $company->id, 'code' => 'TPY', 'name' => 'ΤΠΥ', 'invcount' => 3, 'mydata_type' => '2.1']);

        $bundle = app(CompanyExporter::class)->build($company, 'passphrase', 'p@ss');
        $archive = app(BundleArchive::class);
        $path = storage_path('app/exports/rt-'.uniqid().'.zip');

        $archive->write($path, $bundle);
        $read = $archive->read($path);

        $this->assertSame($bundle['manifest']['company']['slug'], $read['manifest']['company']['slug']);
        $this->assertSame($bundle['company']['afm'], $read['company']['afm']);
        $this->assertSame('TPY', $read['setup']['invoice_types'][0]['code']);
        $this->assertSame($bundle['secrets']['mode'], $read['secrets']['mode']);

        @unlink($path);
    }
}
