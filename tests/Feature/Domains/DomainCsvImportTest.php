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
            ->expectsOutputToContain('0 νέα (αδέσποτα), 1 ενημερώσεις, 1 αμετάβλητα')
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

    public function test_lapsed_expiry_lands_and_stays_expired_never_forever_active(): void
    {
        // Για .gr το CSV είναι η ΜΟΝΗ πηγή αλήθειας μέχρι το grEPP (A4) —
        // κανένα sync δεν θα διορθώσει αργότερα ένα ληγμένο row.
        $past = today()->subMonth()->format('d/m/Y');
        $path = $this->csv("domain;λήξη\nlapsed.gr;{$past}\nalive.gr;31/12/2099");
        $this->artisan('domains:import-csv', ['file' => $path, '--tenant' => $this->company->slug])->assertExitCode(0);

        $this->assertSame(DomainStatus::Expired, Domain::where('fqdn', 'lapsed.gr')->sole()->status);
        $this->assertSame(DomainStatus::Active, Domain::where('fqdn', 'alive.gr')->sole()->status);

        // update path: an Active row whose refreshed expiry is past also lapses
        $path2 = $this->csv("domain;λήξη\nalive.gr;".today()->subDays(3)->format('d/m/Y'));
        $this->artisan('domains:import-csv', ['file' => $path2, '--tenant' => $this->company->slug])->assertExitCode(0);
        $this->assertSame(DomainStatus::Expired, Domain::where('fqdn', 'alive.gr')->sole()->status);
    }

    public function test_reimport_with_the_same_past_date_still_lapses_and_a_renewal_unexpires(): void
    {
        // Direction 1: imported while still valid, lapses later, re-imported
        // with the SAME (unrenewed) date — must not stay «Ενεργό» forever.
        $tld = DomainTld::create(['company_id' => $this->company->id, 'tld' => 'gr', 'is_active' => true]);
        Domain::create([
            'company_id' => $this->company->id, 'domain_tld_id' => $tld->id,
            'sld' => 'stale', 'tld' => 'gr', 'fqdn' => 'stale.gr',
            'expires_at' => today()->subMonth()->toDateString(), 'status' => 'active',
        ]);
        $path = $this->csv("domain;λήξη\nstale.gr;".today()->subMonth()->format('d/m/Y'));
        $this->artisan('domains:import-csv', ['file' => $path, '--tenant' => $this->company->slug])->assertExitCode(0);
        $this->assertSame(DomainStatus::Expired, Domain::where('fqdn', 'stale.gr')->sole()->status);

        // Direction 2: an Expired row renewed at grweb (future expiry in the
        // fresh export) un-expires — nothing else will ever promote a .gr row.
        $path2 = $this->csv("domain;λήξη\nstale.gr;31/12/2099");
        $this->artisan('domains:import-csv', ['file' => $path2, '--tenant' => $this->company->slug])->assertExitCode(0);
        $stale = Domain::where('fqdn', 'stale.gr')->sole();
        $this->assertSame(DomainStatus::Active, $stale->status);
        $this->assertSame('2099-12-31', $stale->expires_at->toDateString());
    }

    public function test_a_name_only_csv_never_unexpires_an_operator_set_status(): void
    {
        $tld = DomainTld::create(['company_id' => $this->company->id, 'tld' => 'gr', 'is_active' => true]);
        Domain::create([
            'company_id' => $this->company->id, 'domain_tld_id' => $tld->id,
            'sld' => 'manual', 'tld' => 'gr', 'fqdn' => 'manual.gr',
            'expires_at' => '2099-06-01', 'status' => 'expired', // operator knows better than the stale date
        ]);

        // no expiry column: the file asserts NOTHING about expiry → no promotion
        $path = $this->csv("domain\nmanual.gr");
        $this->artisan('domains:import-csv', ['file' => $path, '--tenant' => $this->company->slug])->assertExitCode(0);
        $this->assertSame(DomainStatus::Expired, Domain::where('fqdn', 'manual.gr')->sole()->status);

        // fresh future evidence IN the file → un-expires (the round-2 fix)
        $path2 = $this->csv("domain;λήξη\nmanual.gr;31/12/2099");
        $this->artisan('domains:import-csv', ['file' => $path2, '--tenant' => $this->company->slug])->assertExitCode(0);
        $this->assertSame(DomainStatus::Active, Domain::where('fqdn', 'manual.gr')->sole()->status);
    }

    public function test_expiry_refresh_on_a_status_the_deriver_does_not_own_warns(): void
    {
        $tld = DomainTld::create(['company_id' => $this->company->id, 'tld' => 'gr', 'is_active' => true]);
        Domain::create([
            'company_id' => $this->company->id, 'domain_tld_id' => $tld->id,
            'sld' => 'redeem', 'tld' => 'gr', 'fqdn' => 'redeem.gr',
            'expires_at' => today()->subMonth()->toDateString(), 'status' => 'redemption',
        ]);

        $path = $this->csv("domain,expiry\nredeem.gr,2099-12-31");
        $this->artisan('domains:import-csv', ['file' => $path, '--tenant' => $this->company->slug])
            ->expectsOutputToContain('δεν άλλαξε — ελέγξτε το χειροκίνητα')
            ->assertExitCode(0);

        $redeem = Domain::where('fqdn', 'redeem.gr')->sole();
        $this->assertSame('2099-12-31', $redeem->expires_at->toDateString(), 'the expiry truth still lands');
        $this->assertSame(DomainStatus::Redemption, $redeem->status, 'the deriver owns only Active↔Expired');
    }

    public function test_operator_terminal_rows_are_frozen_for_the_csv_too(): void
    {
        $tld = DomainTld::create(['company_id' => $this->company->id, 'tld' => 'gr', 'is_active' => true]);
        Domain::create([
            'company_id' => $this->company->id, 'domain_tld_id' => $tld->id,
            'sld' => 'lost', 'tld' => 'gr', 'fqdn' => 'lost.gr',
            'expires_at' => '2025-06-01', 'status' => 'transferred_away',
        ]);

        $path = $this->csv("domain,expiry\nlost.gr,2030-01-01");
        $this->artisan('domains:import-csv', ['file' => $path, '--tenant' => $this->company->slug])
            ->expectsOutputToContain('1 παραλείφθηκαν')
            ->assertExitCode(0);

        $this->assertSame('2025-06-01', Domain::where('fqdn', 'lost.gr')->sole()->expires_at->toDateString());
    }

    public function test_unpadded_greek_excel_dates_parse(): void
    {
        $path = $this->csv("domain;λήξη\npadded.gr;01/06/2027\nunpadded.gr;1/6/2027\nymd.gr;2027-1-1");
        $this->artisan('domains:import-csv', ['file' => $path, '--tenant' => $this->company->slug])->assertExitCode(0);

        $this->assertSame('2027-06-01', Domain::where('fqdn', 'unpadded.gr')->sole()->expires_at->toDateString());
        $this->assertSame('2027-01-01', Domain::where('fqdn', 'ymd.gr')->sole()->expires_at->toDateString());
        // real overflow still rejected
        $bad = $this->csv("domain;λήξη\noverflow.gr;31/02/2026");
        $this->artisan('domains:import-csv', ['file' => $bad, '--tenant' => $this->company->slug])->assertExitCode(0);
        $this->assertNull(Domain::where('fqdn', 'overflow.gr')->sole()->expires_at);
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

        // ALL rows invalid (wrong column pointed at non-domains) → FAILURE too
        $allInvalid = $this->csv("domain,expiry\nΕνεργό,2027-01-01\nΕνεργό,2027-02-01");
        $this->artisan('domains:import-csv', ['file' => $allInvalid, '--tenant' => $this->company->slug])
            ->assertExitCode(1);

        // explicit column that doesn't exist (name or out-of-range index) → FAILURE
        $ok = $this->csv("domain,expiry\nx.gr,2027-01-01");
        $this->artisan('domains:import-csv', ['file' => $ok, '--tenant' => $this->company->slug, '--expires-col' => '9'])
            ->assertExitCode(1);
        $this->artisan('domains:import-csv', ['file' => $ok, '--tenant' => $this->company->slug, '--domain-col' => 'nosuch'])
            ->assertExitCode(1);

        // headerless + out-of-range explicit expires index: the width check
        // can't see it, but «no value on any row» must still fail loudly
        $ragged = $this->csv("y.gr;2027-01-01\nz.gr;2027-02-01");
        $this->artisan('domains:import-csv', [
            'file' => $ragged, '--tenant' => $this->company->slug,
            '--no-header' => true, '--expires-col' => '5',
        ])->assertExitCode(1);
        $this->assertNull(Domain::where('fqdn', 'x.gr')->first(), 'nothing imported under a mis-mapped file');
        $this->assertNull(Domain::where('fqdn', 'y.gr')->first());
    }
}
