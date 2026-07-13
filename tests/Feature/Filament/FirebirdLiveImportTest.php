<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\FirebirdImportRuns\Pages\CreateFirebirdImportRun;
use App\Jobs\RunFirebirdImport;
use App\Models\Company;
use App\Models\FirebirdImportRun;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The live-connection import path: submitting the «Ζωντανή σύνδεση» tab (no
 * file) records a live run row (uploaded_path null + fb_database set) and
 * dispatches RunFirebirdImport, which detects live mode. Password never lands
 * on the row.
 */
class FirebirdLiveImportTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        Gate::before(fn () => true);
        $this->actingAs(User::create(['name' => 'Admin', 'email' => 'a-'.uniqid().'@t.local', 'password' => bcrypt('x')]));
        $this->tenant = Company::create(['name' => 'MyIP', 'slug' => 'myip-'.uniqid(), 'country_code' => 'GR']);
        Filament::setTenant($this->tenant);
    }

    public function test_live_submission_creates_a_live_run_and_dispatches_the_job(): void
    {
        Bus::fake();

        Livewire::test(CreateFirebirdImportRun::class)
            ->fillForm([
                'fb_live_host' => '10.23.22.5',
                'fb_live_port' => 3050,
                'fb_live_database' => '/opt/Data/ekdosi-myip.fdb',
                'fb_live_user' => 'EKDOSI',
                'fb_live_password' => 'ekdosi1234',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $run = FirebirdImportRun::where('company_id', $this->tenant->id)->latest('id')->first();
        $this->assertNotNull($run);
        $this->assertTrue($run->isLiveConnection());
        $this->assertNull($run->uploaded_path);
        $this->assertSame('/opt/Data/ekdosi-myip.fdb', $run->fb_database);
        $this->assertSame('10.23.22.5', $run->fb_host);
        // Password must NOT be persisted anywhere on the row.
        $this->assertStringNotContainsString('ekdosi1234', json_encode($run->getAttributes()));

        Bus::assertDispatched(RunFirebirdImport::class, fn (RunFirebirdImport $j) => $j->runId === $run->id && $j->fbPassword === 'ekdosi1234');
    }

    public function test_non_default_port_is_folded_into_the_host(): void
    {
        Bus::fake();

        Livewire::test(CreateFirebirdImportRun::class)
            ->fillForm([
                'fb_live_host' => '10.23.22.5',
                'fb_live_port' => 3051,
                'fb_live_database' => '/opt/Data/x.fdb',
                'fb_live_user' => 'EKDOSI',
                'fb_live_password' => 'pw',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $run = FirebirdImportRun::where('company_id', $this->tenant->id)->latest('id')->first();
        $this->assertSame('10.23.22.5/3051', $run->fb_host);
    }
}
