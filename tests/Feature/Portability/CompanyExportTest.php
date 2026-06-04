<?php

namespace Tests\Feature\Portability;

use App\Models\Company;
use App\Models\InvoiceType;
use App\Models\VatCategory;
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
}
