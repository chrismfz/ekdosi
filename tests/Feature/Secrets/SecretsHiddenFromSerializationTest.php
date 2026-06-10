<?php

namespace Tests\Feature\Secrets;

use App\Models\Company;
use App\Models\CompanyBackupSetting;
use App\Models\Server;
use App\Models\ServerGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Secret columns must not leak through array/JSON serialization (toArray/toJson —
 * the path a stray log/dump/API response takes), now that they're plaintext at
 * rest by default. Attribute access still returns them (the app needs them).
 */
class SecretsHiddenFromSerializationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function company_secrets_are_hidden_but_still_readable(): void
    {
        $c = Company::create([
            'name' => 'Sec', 'slug' => 'sec-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'afm' => '800561849',
            'gsis_password' => 'gsis-pw', 'mail_smtp_password' => 'smtp-pw',
            'whmcs_api_secret' => 'whmcs-sec', 'whmcs_webhook_secret' => 'hook',
            'mydata_subscription_key_production' => 'PRODKEY',
            'einvoice_provider_config' => ['token' => 'T-1'],
        ]);

        $array = $c->fresh()->toArray();
        $json = $c->fresh()->toJson();
        foreach ([
            'gsis_password', 'mail_smtp_password', 'whmcs_api_secret', 'whmcs_webhook_secret',
            'mydata_subscription_key_production', 'mydata_subscription_key_sandbox', 'einvoice_provider_config',
        ] as $col) {
            $this->assertArrayNotHasKey($col, $array, "{$col} leaked into toArray()");
        }
        foreach (['gsis-pw', 'smtp-pw', 'whmcs-sec', 'PRODKEY', 'T-1'] as $secret) {
            $this->assertStringNotContainsString($secret, $json, "secret value leaked into toJson()");
        }

        // Attribute access still works (the app reads these).
        $this->assertSame('gsis-pw', $c->fresh()->gsis_password);
        $this->assertSame(['token' => 'T-1'], $c->fresh()->einvoice_provider_config);
    }

    #[Test]
    public function server_and_backup_secrets_are_hidden(): void
    {
        $c = Company::create(['name' => 'S', 'slug' => 's-'.uniqid(), 'country_code' => 'GR']);
        $group = ServerGroup::create([
            'company_id' => $c->id, 'name' => 'g', 'module' => 'cpanel',
            'username' => 'u', 'secret_encrypted' => 'group-secret',
        ]);
        $server = Server::create([
            'company_id' => $c->id, 'server_group_id' => $group->id, 'name' => 'srv',
            'hostname' => 'h', 'secret_encrypted' => 'server-secret',
        ]);
        $bk = CompanyBackupSetting::create([
            'company_id' => $c->id, 'enabled' => true, 'frequency' => 'daily',
            'bucket' => 'settings_setup', 'secrets_mode' => 'passphrase', 'passphrase' => 'bk-pass',
        ]);

        $this->assertArrayNotHasKey('secret_encrypted', $group->fresh()->toArray());
        $this->assertArrayNotHasKey('secret_encrypted', $server->fresh()->toArray());
        $this->assertArrayNotHasKey('passphrase', $bk->fresh()->toArray());

        // Still readable via attribute access.
        $this->assertSame('group-secret', $group->fresh()->secret_encrypted);
        $this->assertSame('bk-pass', $bk->fresh()->passphrase);
    }
}
