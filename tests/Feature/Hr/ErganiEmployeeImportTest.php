<?php

namespace Tests\Feature\Hr;

use App\Filament\Resources\Employees\Pages\ListEmployees;
use App\Models\Employee;
use App\Services\Ergani\ErganiClient;
use App\Services\Ergani\ErganiEmployeeImporter;
use App\Services\TenantRoleProvisioner;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

class ErganiEmployeeImportTest extends HrTestCase
{
    /** @var list<string> */
    private array $urls = [];

    protected function setUp(): void
    {
        parent::setUp();
        // Trial mode on purpose: the import must still read PRODUCTION.
        $this->company->forceFill(['ergani_mode' => 'trial', 'ergani_username' => 'U', 'ergani_password' => 'P'])->save();
        Cache::flush();
    }

    private function row(string $afm, string $last, string $first, int $branch = 0): array
    {
        // Shape verified live (2026-09-25); the sensitive fields exist and must NOT be kept.
        return ['afm' => $afm, 'Eponimo' => $last, 'Onoma' => $first, 'PararthmaAa' => $branch,
            'DateFrom' => '2021-03-01T00:00:00+02:00', 'Amka' => '01018012345', 'ArTaytotitas' => 'ΑΖ123456',
            'Apodoxes' => '920.00', 'Dieythinsi' => 'ΚΑΝΑΡΗ 1'];
    }

    private function fake(array $rows, int $status = 200): void
    {
        Http::fake(function (Request $request) use ($rows, $status) {
            $this->urls[] = $request->url();
            if (str_ends_with($request->url(), '/Authentication')) {
                return Http::response(['accessToken' => 'tok'], 200);
            }

            return $status === 200
                ? Http::response(['EX_BASE_05' => ['Cur' => $rows]], 200)
                : Http::response(['message' => 'Criteria doesn\'t meet requirements'], $status);
        });
    }

    public function test_fetch_reads_production_and_keeps_only_minimal_fields(): void
    {
        $this->fake([$this->row('123456789', 'ΑΝΤΩΝΙΟΥ', 'ΗΛΙΑΣ', 1), ['afm' => 'bad'] + $this->row('x', 'Χ', 'Χ')]);

        $rows = app(ErganiEmployeeImporter::class)->fetch($this->company);

        $this->assertSame([['afm' => '123456789', 'last_name' => 'Αντωνιου', 'first_name' => 'Ηλιας', 'branch' => 1, 'hired_at' => '2021-03-01']], $rows);
        $this->assertNotEmpty($this->urls);
        foreach ($this->urls as $url) {
            $this->assertStringStartsWith(ErganiClient::PRODUCTION_URL, $url);
        }
        $this->assertSame('trial', $this->company->fresh()->ergani_mode, 'the company mode is never changed');
    }

    public function test_import_creates_new_updates_existing_skips_deleted_and_ignores_forged_afms(): void
    {
        $this->actAs($this->makeUser(TenantRoleProvisioner::ROLE_COMPANY_ADMIN));
        $existing = Employee::create(['company_id' => $this->company->id, 'afm' => '222222222', 'last_name' => 'Τοπικό', 'first_name' => 'Όνομα', 'ergani_branch' => 0]);
        $deleted = Employee::create(['company_id' => $this->company->id, 'afm' => '333333333', 'last_name' => 'Διαγραμμένος', 'first_name' => 'Χ']);
        $deleted->delete();
        Employee::create(['company_id' => $this->company->id, 'afm' => '444444444', 'last_name' => 'Έφυγε', 'first_name' => 'Κάποιος']);

        $this->fake([
            $this->row('111111111', 'ΝΕΟΣ', 'ΠΡΩΤΟΣ'),
            $this->row('222222222', 'ΑΛΛΟ', 'ΟΝΟΜΑ', 2),
            $this->row('333333333', 'ΔΙΑΓΡΑΜΜΕΝΟΣ', 'Χ'),
        ]);

        Livewire::test(ListEmployees::class)
            ->mountAction('importFromErgani')
            ->assertMountedActionModalSee(['Νέος — θα προστεθεί', 'Υπάρχει ως «Τοπικό Όνομα»', 'Υπάρχει στους διαγραμμένους',
                'Έφυγε'])                                          // active here, missing from ΕΡΓΑΝΗ
            ->assertSchemaStateSet(['afms' => ['111111111']], 'mountedActionSchema0')
            ->setActionData(['afms' => ['111111111', '222222222']])
            ->callMountedAction()
            ->assertHasNoFormErrors();

        $new = Employee::query()->where('afm', '111111111')->sole();
        $this->assertSame(['Νεος', 'Πρωτος', '2021-03-01', true], [$new->last_name, $new->first_name, $new->hired_at->toDateString(), $new->is_active]);
        $this->assertSame(['Τοπικό', 2], [$existing->fresh()->last_name, $existing->fresh()->ergani_branch], 'names kept, branch synced');
        $this->assertTrue($deleted->fresh()->trashed());
        $this->assertSame(4, Employee::withTrashed()->count(), 'nothing else created, nothing deleted');

        // The deleted one is disabled in the form; even if forced through, it's skipped.
        $this->assertSame(['created' => 0, 'updated' => 0, 'skipped' => 1], app(ErganiEmployeeImporter::class)->apply($this->company, ['333333333']));
        $this->assertTrue($deleted->fresh()->trashed());

        // A forged ΑΦΜ (not in the fresh ΕΡΓΑΝΗ read) is never created.
        $r = app(ErganiEmployeeImporter::class)->apply($this->company, ['999999999']);
        $this->assertSame(['created' => 0, 'updated' => 0, 'skipped' => 1], $r);
        $this->assertNull(Employee::query()->where('afm', '999999999')->first());
    }

    public function test_ergani_error_shows_in_the_modal_and_nothing_is_imported(): void
    {
        $this->actAs($this->makeUser(TenantRoleProvisioner::ROLE_COMPANY_ADMIN));
        $this->fake([], 400);

        Livewire::test(ListEmployees::class)
            ->mountAction('importFromErgani')
            ->assertMountedActionModalSee('Δεν ήταν δυνατή η ανάγνωση από το ΕΡΓΑΝΗ');
        $this->assertSame(0, Employee::count());
    }

    public function test_only_employee_creators_see_the_import(): void
    {
        $this->actAs($this->makeUser(TenantRoleProvisioner::ROLE_OPERATOR));
        $this->assertFalse(auth()->user()->can('create', Employee::class));

        $this->actAs($this->makeUser(TenantRoleProvisioner::ROLE_COMPANY_ADMIN));
        $this->company->forceFill(['ergani_username' => null])->save();
        Livewire::test(ListEmployees::class)->assertActionHidden('importFromErgani');
    }

    public function test_rendering_the_list_never_calls_ergani(): void
    {
        $this->actAs($this->makeUser(TenantRoleProvisioner::ROLE_COMPANY_ADMIN));
        $this->fake([$this->row('111111111', 'ΝΕΟΣ', 'ΠΡΩΤΟΣ')]);

        Livewire::test(ListEmployees::class)->assertActionVisible('importFromErgani')->call('$refresh');

        $this->assertSame([], $this->urls, 'only opening the modal may read ΕΡΓΑΝΗ');
    }
}
