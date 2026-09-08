<?php

namespace Tests\Feature\Domains;

use App\Enums\DomainStatus;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Domain;
use App\Models\DomainTld;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Πυλώνας A / A2c — the CSV bootstrap (grweb export for .gr — the .gr EPP has
 * no list command, README §9). Same money guards as the registrar import:
 * rows land ΑΔΕΣΠΟΤΑ, existing rows only refresh expiry, tombstones/deleted
 * TLDs never resurrect, and a file that maps to nothing fails loudly.
 */
class DomainCsvImportTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    /** @var list<string> temp files to unlink */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::create([
            'name' => 'Dom', 'slug' => 'd-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
            'enable_domain_management' => true,
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    private function csv(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ekdosi-csv-test-');
        file_put_contents($path, $content);
        $this->files[] = $path;

        return $path;
    }

    public function test_grweb_style_semicolon_csv_lands_unassigned_domains(): void
    {
        $path = $this->csv(implode("\n", [
            "\u{FEFF}Όνομα Domain;Κατάσταση;Ημερομηνία Λήξης",
            'mysite.gr;Ενεργό;31/12/2027',
            'shop.com.gr;Ενεργό;15/06/2028',
        ]));

        $this->artisan('domains:import-csv', ['file' => $path, '--tenant' => $this->company->slug])
            ->expectsOutputToContain('2 νέα (αδέσποτα)')
            ->assertExitCode(0);

        $mysite = Domain::where('fqdn', 'mysite.gr')->sole();
        $this->assertNull($mysite->customer_id, 'CSV domains land ΑΔΕΣΠΟΤΑ');
        $this->assertSame('2027-12-31', $mysite->expires_at->toDateString(), 'd/m/Y parsed');
        $this->assertFalse($mysite->auto_renew, 'option β — assignment turns it on');
        $this->assertSame(DomainStatus::Active, $mysite->status);
        $this->assertNull($mysite->last_synced_at, 'a CSV is not the registrar clock');
        $this->assertSame('csv', $mysite->module_meta['imported_from']);

        // first-dot split keeps the official second-level TLDs whole
        $shop = Domain::where('fqdn', 'shop.com.gr')->sole();
        $this->assertSame('shop', $shop->sld);
        $this->assertSame('com.gr', $shop->tld);

        // TLD rows auto-created UNROUTED (manual — grEPP routing is A4)
        $this->assertNull(DomainTld::where('tld', 'gr')->sole()->registrar_connection_id);
        $this->assertNull(DomainTld::where('tld', 'com.gr')->sole()->registrar_connection_id);
    }

    public function test_rerun_refreshes_expiry_only_and_reports_unchanged(): void
    {
        $customer = Customer::create(['company_id' => $this->company->id, 'name' => 'Πελάτης']);
        $path1 = $this->csv("domain,expiry\nmysite.gr,2027-12-31\nother.gr,2027-01-01");
        $this->artisan('domains:import-csv', ['file' => $path1, '--tenant' => $this->company->slug])->assertExitCode(0);

        // operator assigns one + flips auto_renew — the re-run must not touch either
        Domain::where('fqdn', 'mysite.gr')->sole()
            ->forceFill(['customer_id' => $customer->id, 'auto_renew' => true])->save();

        $path2 = $this->csv("domain,expiry\nmysite.gr,2029-12-31\nother.gr,2027-01-01");
        $this->artisan('domains:import-csv', ['file' => $path2, '--tenant' => $this->company->slug])
            ->expectsOutputToContain('0 νέα (αδέσποτα), 1 ενημερώσεις λήξης, 1 αμετάβλητα')
            ->assertExitCode(0);

        $mysite = Domain::where('fqdn', 'mysite.gr')->sole();
        $this->assertSame('2029-12-31', $mysite->expires_at->toDateString());
        $this->assertSame($customer->id, $mysite->customer_id, 'customer_id NEVER touched');
        $this->assertTrue($mysite->auto_renew, 'auto_renew NEVER touched on existing rows');
    }

    public function test_invalid_rows_and_bad_dates_warn_without_killing_the_run(): void
    {
        $path = $this->csv(implode("\n", [
            'domain,expiry',
            'not a domain,2027-01-01',
            'baddate.gr,soon™',
            '',
            'good.gr,2027-01-01',
        ]));

        $this->artisan('domains:import-csv', ['file' => $path, '--tenant' => $this->company->slug])
            ->expectsOutputToContain('1 μη έγκυρες γραμμές')
            ->assertExitCode(0);

        $this->assertSame(2, Domain::where('company_id', $this->company->id)->count());
        $this->assertNull(Domain::where('fqdn', 'baddate.gr')->sole()->expires_at, 'bad date → imported without expiry (unbillable)');
        $this->assertNotNull(Domain::where('fqdn', 'good.gr')->sole()->expires_at);
    }

    public function test_tombstones_and_deleted_tlds_are_never_resurrected(): void
    {
        $tld = DomainTld::create(['company_id' => $this->company->id, 'tld' => 'gr', 'is_active' => true]);
        $dead = Domain::create([
            'company_id' => $this->company->id, 'domain_tld_id' => $tld->id,
            'sld' => 'dead', 'tld' => 'gr', 'fqdn' => 'dead.gr', 'expires_at' => '2025-01-01',
        ]);
        $dead->delete();
        DomainTld::create(['company_id' => $this->company->id, 'tld' => 'org', 'is_active' => true])->delete();

        $path = $this->csv("domain,expiry\ndead.gr,2028-01-01\nsomething.org,2028-01-01");
        $this->artisan('domains:import-csv', ['file' => $path, '--tenant' => $this->company->slug])
            ->expectsOutputToContain('2 παραλείφθηκαν')
            ->assertExitCode(0);

        $this->assertNotNull(Domain::withTrashed()->where('fqdn', 'dead.gr')->sole()->deleted_at);
        $this->assertSame('2025-01-01', Domain::withTrashed()->where('fqdn', 'dead.gr')->sole()->expires_at->toDateString());
        $this->assertNull(Domain::withTrashed()->where('fqdn', 'something.org')->first());
    }

    public function test_headerless_files_use_explicit_or_first_column(): void
    {
        $path = $this->csv("plain.gr;31/12/2027\nsecond.gr;15/06/2028");

        $this->artisan('domains:import-csv', [
            'file' => $path, '--tenant' => $this->company->slug,
            '--no-header' => true, '--expires-col' => '1',
        ])->assertExitCode(0);

        $this->assertSame('2027-12-31', Domain::where('fqdn', 'plain.gr')->sole()->expires_at->toDateString());
        $this->assertSame(2, Domain::where('company_id', $this->company->id)->count());
    }

    public function test_failures_are_loud(): void
    {
        // tenant is mandatory — a CSV belongs to exactly one company
        $path = $this->csv("domain\nx.gr");
        $this->artisan('domains:import-csv', ['file' => $path])->assertExitCode(1);
        $this->artisan('domains:import-csv', ['file' => $path, '--tenant' => 'nope'])->assertExitCode(1);

        // missing file
        $this->artisan('domains:import-csv', ['file' => '/no/such/file.csv', '--tenant' => $this->company->slug])
            ->assertExitCode(1);

        // no recognizable domain column
        $bad = $this->csv("foo,bar\n1,2");
        $this->artisan('domains:import-csv', ['file' => $bad, '--tenant' => $this->company->slug])
            ->assertExitCode(1);

        // header-only file: nothing recognized → wrong file, not a quiet success
        $empty = $this->csv('domain,expiry');
        $this->artisan('domains:import-csv', ['file' => $empty, '--tenant' => $this->company->slug])
            ->assertExitCode(1);
    }
}
