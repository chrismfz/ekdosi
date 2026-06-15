<?php

namespace Tests\Feature\OperatorHealth;

use App\Models\Company;
use App\Models\CompanyBackupRun;
use App\Models\CompanyBackupSetting;
use App\Support\OperatorHealth\OperatorHealthReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * (b) Off-site backup verification in ops:health: enabled per-tenant backups
 * must actually leave the VM (sftp/ftp/s3), and the last off-site push must have
 * landed — else `offsite_gap` warns.
 */
class OffsiteBackupHealthTest extends TestCase
{
    use RefreshDatabase;

    private function company(string $slug): Company
    {
        return Company::create([
            'name' => $slug, 'slug' => $slug, 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
    }

    /** @return array<string,mixed> */
    private function companyBackups(): array
    {
        return (new OperatorHealthReport)->build()['backup']['companies'];
    }

    #[Test]
    public function local_only_tenant_is_flagged_as_an_offsite_gap(): void
    {
        $c = $this->company('localonly');
        CompanyBackupSetting::create([
            'company_id' => $c->id, 'enabled' => true, 'frequency' => 'daily',
            'secrets_mode' => 'passphrase', 'destinations' => [['driver' => 'local']],
        ]);

        $cb = $this->companyBackups();

        $this->assertTrue($cb['offsite_gap']);
        $this->assertSame(1, $cb['enabled_count']);
        $this->assertFalse($cb['companies'][0]['offsite_configured']);
    }

    #[Test]
    public function offsite_destination_with_successful_push_is_ok(): void
    {
        $c = $this->company('offsite');
        CompanyBackupSetting::create([
            'company_id' => $c->id, 'enabled' => true, 'frequency' => 'daily',
            'secrets_mode' => 'raw',
            'destinations' => [['driver' => 'local'], ['driver' => 'sftp', 'host' => 'x']],
        ]);
        CompanyBackupRun::create([
            'company_id' => $c->id, 'started_at' => now(), 'finished_at' => now(),
            'trigger' => 'manual', 'bucket' => 'settings_setup', 'secrets_mode' => 'raw', 'status' => 'ok',
            'destinations' => [
                ['driver' => 'local', 'status' => 'ok'],
                ['driver' => 'sftp', 'status' => 'ok', 'location' => 'sftp://x/b.zip'],
            ],
        ]);

        $cb = $this->companyBackups();

        $this->assertFalse($cb['offsite_gap']);
        $this->assertTrue($cb['companies'][0]['offsite_configured']);
        $this->assertTrue($cb['companies'][0]['offsite_push_ok']);
    }

    #[Test]
    public function offsite_configured_but_last_push_failed_is_a_gap(): void
    {
        $c = $this->company('offsitefail');
        CompanyBackupSetting::create([
            'company_id' => $c->id, 'enabled' => true, 'frequency' => 'daily',
            'secrets_mode' => 'raw', 'destinations' => [['driver' => 's3', 'bucket' => 'b']],
        ]);
        CompanyBackupRun::create([
            'company_id' => $c->id, 'started_at' => now(), 'finished_at' => now(),
            'trigger' => 'scheduled', 'bucket' => 'settings_setup', 'secrets_mode' => 'raw', 'status' => 'partial',
            'destinations' => [['driver' => 's3', 'status' => 'failed', 'message' => 'denied']],
        ]);

        $cb = $this->companyBackups();

        $this->assertTrue($cb['offsite_gap']);
        $this->assertFalse($cb['companies'][0]['offsite_push_ok']);
    }

    #[Test]
    public function disabled_backups_are_not_counted(): void
    {
        $c = $this->company('disabledco');
        CompanyBackupSetting::create([
            'company_id' => $c->id, 'enabled' => false, 'frequency' => 'daily',
            'secrets_mode' => 'raw', 'destinations' => [['driver' => 'local']],
        ]);

        $cb = $this->companyBackups();

        $this->assertSame(0, $cb['enabled_count']);
        $this->assertFalse($cb['offsite_gap']);
    }
}
