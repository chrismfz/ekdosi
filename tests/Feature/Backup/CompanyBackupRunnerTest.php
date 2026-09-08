<?php

namespace Tests\Feature\Backup;

use App\Models\Company;
use App\Models\CompanyBackupSetting;
use App\Services\Backup\CompanyBackupRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CompanyBackupRunnerTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(): Company
    {
        return Company::create([
            'name' => 'Backup tenant', 'slug' => 'bk-'.uniqid(), 'country_code' => 'GR', 'afm' => '800561849',
        ]);
    }

    private function settings(Company $c, array $overrides = []): CompanyBackupSetting
    {
        return CompanyBackupSetting::create(array_merge([
            'company_id' => $c->id, 'enabled' => true, 'frequency' => 'daily',
            'bucket' => 'settings_setup', 'secrets_mode' => 'raw', 'retention_keep' => 2,
        ], $overrides));
    }

    #[Test]
    public function a_local_backup_run_writes_a_bundle_and_logs_ok(): void
    {
        Storage::fake('local');
        $company = $this->tenant();

        $run = app(CompanyBackupRunner::class)->run($company, $this->settings($company), 'manual');

        $this->assertSame('ok', $run->status);
        $this->assertSame('manual', $run->trigger);
        $this->assertGreaterThan(0, $run->bytes);
        $this->assertNotNull($run->bundle_path);
        $this->assertTrue(
            collect($run->destinations)->contains(fn ($d) => $d['driver'] === 'local' && $d['status'] === 'ok'),
            'local destination should be recorded ok'
        );
        $this->assertCount(1, Storage::disk('local')->files('company-backups/'.$company->slug));
        // The temp build artifact is cleaned up (no leftover zip for this slug).
        $leftover = glob(storage_path('app/tmp/'.$company->slug.'-*.zip')) ?: [];
        $this->assertSame([], $leftover);
    }

    #[Test]
    public function local_is_always_included_even_when_only_remotes_configured(): void
    {
        Storage::fake('local');
        $company = $this->tenant();
        // Configure NO local entry — runner must still produce the downloadable local copy.
        $settings = $this->settings($company, ['destinations' => [['driver' => 'local']]]);
        $settings->forceFill(['destinations' => []])->save(); // empty list → defaults to local anyway

        $run = app(CompanyBackupRunner::class)->run($company, $settings->fresh(), 'scheduled');

        $this->assertSame('ok', $run->status);
        $this->assertCount(1, Storage::disk('local')->files('company-backups/'.$company->slug));
    }

    #[Test]
    public function retention_keeps_only_the_newest_n(): void
    {
        Storage::fake('local');
        $company = $this->tenant();
        $settings = $this->settings($company, ['retention_keep' => 2]);
        $runner = app(CompanyBackupRunner::class);

        // Three runs at distinct timestamps → three distinct filenames; keep=2.
        $this->travelTo(now()->setTime(2, 0, 0));
        $runner->run($company, $settings, 'scheduled');
        $this->travelTo(now()->addMinute());
        $runner->run($company, $settings, 'scheduled');
        $this->travelTo(now()->addMinute());
        $runner->run($company, $settings, 'scheduled');
        $this->travelBack();

        $this->assertCount(2, Storage::disk('local')->files('company-backups/'.$company->slug));
    }

    #[Test]
    public function passphrase_mode_without_a_passphrase_fails_cleanly(): void
    {
        Storage::fake('local');
        $company = $this->tenant();
        $settings = $this->settings($company, ['secrets_mode' => 'passphrase', 'passphrase' => null]);

        $run = app(CompanyBackupRunner::class)->run($company, $settings, 'manual');

        $this->assertSame('failed', $run->status);
        $this->assertStringContainsString('συνθηματικό', (string) $run->message);
        $this->assertNull($run->bundle_path);
        $this->assertSame([], Storage::disk('local')->files('company-backups/'.$company->slug));
    }
}
