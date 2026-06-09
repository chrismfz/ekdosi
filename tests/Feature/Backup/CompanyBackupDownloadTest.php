<?php

namespace Tests\Feature\Backup;

use App\Models\Company;
use App\Models\CompanyBackupRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The signed streaming download route (CompanyBackupDownloadController): a backup
 * bundle is served by a short-lived signed link, not buffered through Livewire.
 */
class CompanyBackupDownloadTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    private string $bundle;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'name' => 'Backup tenant', 'slug' => 'bk-'.uniqid(), 'country_code' => 'GR', 'afm' => '800561849',
        ]);
        $this->user = User::create([
            'name' => 'Admin', 'email' => 'adm-'.uniqid().'@test.local', 'password' => bcrypt('x'),
        ]);

        $this->bundle = tempnam(sys_get_temp_dir(), 'bk').'.zip';
        file_put_contents($this->bundle, 'BUNDLE-BYTES');
    }

    protected function tearDown(): void
    {
        @unlink($this->bundle);
        parent::tearDown();
    }

    private function makeRun(bool $withFile = true): CompanyBackupRun
    {
        return CompanyBackupRun::create([
            'company_id' => $this->company->id, 'started_at' => now(), 'trigger' => 'manual',
            'bucket' => 'settings_setup', 'secrets_mode' => 'raw', 'bytes' => 12, 'status' => 'ok',
            'bundle_path' => $withFile ? $this->bundle : null,
        ]);
    }

    private function signedUrl(CompanyBackupRun $run): string
    {
        return URL::temporarySignedRoute('company-backups.download', now()->addMinutes(15), ['run' => $run->getKey()]);
    }

    #[Test]
    public function a_permitted_user_with_a_signed_link_downloads_the_bundle(): void
    {
        Gate::define('View:Company', fn () => true);
        $run = $this->makeRun();

        $this->actingAs($this->user)
            ->get($this->signedUrl($run))
            ->assertOk()
            ->assertDownload(basename($this->bundle));
    }

    #[Test]
    public function a_user_without_view_company_is_forbidden(): void
    {
        // No Gate ability defined → can('View:Company') is false → 403.
        $run = $this->makeRun();

        $this->actingAs($this->user)
            ->get($this->signedUrl($run))
            ->assertForbidden();
    }

    #[Test]
    public function an_unsigned_or_tampered_link_is_rejected(): void
    {
        Gate::define('View:Company', fn () => true);
        $run = $this->makeRun();

        // Plain (unsigned) route URL → the `signed` middleware rejects it.
        $this->actingAs($this->user)
            ->get(route('company-backups.download', ['run' => $run->getKey()]))
            ->assertForbidden();

        // Tampered signed URL (extra query param invalidates the signature).
        $this->actingAs($this->user)
            ->get($this->signedUrl($run).'&x=1')
            ->assertForbidden();
    }

    #[Test]
    public function a_run_with_no_local_file_is_not_found(): void
    {
        Gate::define('View:Company', fn () => true);
        $run = $this->makeRun(withFile: false); // remote-only / pruned / failed run

        $this->actingAs($this->user)
            ->get($this->signedUrl($run))
            ->assertNotFound();
    }
}
