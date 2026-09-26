<?php

namespace Tests\Feature\Hr;

use App\Enums\LeaveStatus;
use App\Enums\LeaveType;
use App\Filament\Resources\Companies\Pages\EditCompany;
use App\Filament\Resources\ErganiSubmissions\ErganiSubmissionResource;
use App\Filament\Resources\ErganiSubmissions\Pages\ListErganiSubmissions;
use App\Filament\Resources\LeaveRequests\Pages\ViewLeaveRequest;
use App\Mail\ErganiGoLiveMail;
use App\Mail\LeaveAccountantMail;
use App\Models\Activity;
use App\Models\Employee;
use App\Models\ErganiSubmission;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\Ergani\ErganiClient;
use App\Services\Ergani\ErganiGoLive;
use App\Services\Portability\CompanyImporter;
use App\Services\TenantMailerFactory;
use App\Services\TenantRoleProvisioner;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

/** «Ιστορικό ΕΡΓΑΝΗ», the official PDF (history, accountant mail, own leave) and the «Πέρασμα σε Παραγωγή» wizard. */
class ErganiHistoryGoLiveTest extends HrTestCase
{
    /** @var list<string> */
    private array $urls = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['ViewAny:ErganiSubmission', 'View:ErganiSubmission'] as $p) {
            Permission::findOrCreate($p, 'web');
        }
        app(TenantRoleProvisioner::class)->ensureStandardRoles($this->company);
        $this->company->forceFill(['afm' => '800561849', 'ergani_mode' => 'trial', 'ergani_username' => 'U', 'ergani_password' => 'P'])->save();
        Cache::flush();
    }

    private function fake(string $inSector = '0', string $afm = '800561849'): void
    {
        Http::fake(function (Request $r) use ($afm, $inSector) {
            $this->urls[] = $r->url();

            return match (true) {
                str_ends_with($r->url(), '/Authentication') => Http::response(['accessToken' => 'tok']),
                str_contains($r->url(), '/Documents/') && $r->method() === 'GET' => Http::response(['document' => base64_encode('%PDF-1.7 fake')]),
                default => Http::response(['EX_BASE_01' => ['Ergodotis' => ['Afm' => $afm, 'Eponimia' => 'MYIP', 'IsInCardSector' => $inSector]]]),
            };
        });
    }

    private function submission(array $attrs = []): ErganiSubmission
    {
        return ErganiSubmission::create($attrs + ['company_id' => $this->company->id, 'document' => 'WTOLeave', 'action' => 'submit',
            'environment' => 'trial', 'ok' => true, 'protocol' => 'ΑΚ - ΟΡ1', 'submit_date' => '25/09/2026 21:45', 'request' => ['x' => 1]]);
    }

    public function test_history_is_admin_only_read_only_and_serves_the_pdf(): void
    {
        $this->fake();
        $ok = $this->submission();
        $failed = $this->submission(['ok' => false, 'protocol' => null, 'http_status' => 400, 'message' => 'Λάθος']);

        $this->actAs($this->makeUser(TenantRoleProvisioner::ROLE_COMPANY_ADMIN));
        $this->assertTrue(ErganiSubmissionResource::canAccess());
        $this->assertFalse(auth()->user()->can('create', ErganiSubmission::class), 'append-only');
        Livewire::test(ListErganiSubmissions::class)
            ->assertCanSeeTableRecords([$ok, $failed])
            ->assertTableActionVisible('erganiPdf', $ok)
            ->assertTableActionHidden('erganiPdf', $failed)
            ->callTableAction('erganiPdf', $ok)
            ->assertFileDownloaded();
        $this->assertTrue(collect($this->urls)->contains(fn (string $u) => str_contains($u, '/Documents/WTOLeave?protocol=') && str_contains($u, 'submittedDate=20260925')));

        $this->actAs($this->makeUser(TenantRoleProvisioner::ROLE_OPERATOR));
        $this->assertFalse(ErganiSubmissionResource::canAccess());
    }

    public function test_accountant_mail_attaches_the_pdf_only_for_a_production_declaration(): void
    {
        $this->fake();
        $e = $this->employeeFor(null);
        $leave = LeaveRequest::create(['company_id' => $this->company->id, 'employee_id' => $e->id, 'type' => LeaveType::Annual,
            'starts_on' => '2026-10-05', 'ends_on' => '2026-10-06', 'days' => 2, 'status' => LeaveStatus::Approved]);
        $leave->forceFill(['ergani_status' => 'submitted', 'ergani_env' => 'production', 'ergani_protocol' => 'ΑΚ - ΟΡ9'])->saveQuietly();
        $this->submission(['leave_request_id' => $leave->id, 'environment' => 'production', 'protocol' => 'ΑΚ - ΟΡ9']);

        $mail = new LeaveAccountantMail($leave->fresh(), 'approved', 'a@b.c', 'X');
        $this->assertCount(1, $mail->attachments());
        foreach ($this->urls as $u) {
            if (str_contains($u, '/Documents/')) {
                $this->assertStringStartsWith(ErganiClient::PRODUCTION_URL, $u, 'fetched from the environment it was declared in');
            }
        }

        $leave->forceFill(['ergani_env' => 'trial'])->saveQuietly();
        $this->assertSame([], (new LeaveAccountantMail($leave->fresh(), 'approved', 'a@b.c', 'X'))->attachments(), 'trial → no PDF');
    }

    public function test_employee_can_download_the_pdf_of_their_own_declared_leave(): void
    {
        $user = $this->makeUser(TenantRoleProvisioner::ROLE_ERGANI);
        $leave = LeaveRequest::create(['company_id' => $this->company->id, 'employee_id' => $this->employeeFor($user)->id, 'type' => LeaveType::Annual,
            'starts_on' => '2026-10-05', 'ends_on' => '2026-10-06', 'days' => 2, 'status' => LeaveStatus::Approved]);
        $leave->forceFill(['ergani_status' => 'submitted', 'ergani_env' => 'trial', 'ergani_protocol' => 'ΑΚ - ΟΡ9'])->saveQuietly();

        $this->actAs($user);
        Livewire::test(ViewLeaveRequest::class, ['record' => $leave->getRouteKey()])
            ->assertActionVisible('erganiPdf');
    }

    public function test_go_live_checks_block_without_afm_and_switch_with_the_accountant_told(): void
    {
        Mail::fake();
        $this->fake();
        $this->employeeFor(null)->forceFill(['afm' => null])->save();
        $by = $this->makeUser(TenantRoleProvisioner::ROLE_COMPANY_ADMIN);

        $checks = app(ErganiGoLive::class)->checks($this->company->fresh());
        $this->assertTrue($checks['connection']['ok']);
        $this->assertTrue($checks['blocking'], 'an active employee without ΑΦΜ blocks');
        $this->assertNotNull(app(ErganiGoLive::class)->goLive($this->company->fresh(), $by, true)['error']);
        $this->assertSame('trial', $this->company->fresh()->ergani_mode);

        Employee::query()->update(['afm' => '123456783']);
        $this->assertSame(['error' => null, 'mailed' => true], app(ErganiGoLive::class)->goLive($this->company->fresh(), $by, true));
        $fresh = $this->company->fresh();
        $this->assertSame(['production', $by->id], [$fresh->ergani_mode, $fresh->ergani_production_by_user_id]);
        $this->assertNotNull($fresh->ergani_production_since);
        Mail::assertSent(ErganiGoLiveMail::class, fn (ErganiGoLiveMail $m) => $m->hasTo('accountant@example.test'));

        app(ErganiGoLive::class)->backToTrial($fresh);
        $this->assertSame(['trial', null], [$this->company->fresh()->ergani_mode, $this->company->fresh()->ergani_production_since]);
    }

    public function test_go_live_refuses_a_wrong_afm_and_trial_only_items_are_listed(): void
    {
        $this->fake('0', '999999999');
        $e = $this->employeeFor(null);
        LeaveRequest::create(['company_id' => $this->company->id, 'employee_id' => $e->id, 'type' => LeaveType::Annual,
            'starts_on' => now()->addWeek()->toDateString(), 'ends_on' => now()->addWeek()->toDateString(), 'days' => 1, 'status' => LeaveStatus::Approved])
            ->forceFill(['ergani_status' => 'submitted', 'ergani_env' => 'trial'])->saveQuietly();

        $checks = app(ErganiGoLive::class)->checks($this->company->fresh());
        $this->assertFalse($checks['connection']['ok']);
        $this->assertStringContainsString('δεν ταιριάζει', $checks['connection']['text']);
        $this->assertCount(1, $checks['trial_only']);
    }

    public function test_company_page_never_calls_ergani_until_the_wizard_opens_and_needs_the_checkbox(): void
    {
        Gate::before(fn () => true);
        $this->fake();
        $this->actingAs(User::create(['name' => 'SA', 'email' => 'sa-'.uniqid().'@test.local', 'password' => bcrypt('x')]));
        Filament::setTenant($this->company);

        $page = Livewire::test(EditCompany::class, ['record' => $this->company->getRouteKey()]);
        $this->assertSame([], $this->urls, 'a plain render must not reach ΕΡΓΑΝΗ production');

        // A plain save can never switch to production (the field is display-only).
        $page->set('data.ergani_mode', 'production')->call('save')->assertHasNoFormErrors();
        $this->assertSame('trial', $this->company->fresh()->ergani_mode);
        $this->assertSame([], $this->urls);

        $page->mountAction(TestAction::make('ergani_go_live')->schemaComponent('ergani-connection'))
            ->assertMountedActionModalSee('Σύνδεση Παραγωγής OK')
            ->setActionData(['accountant_told' => false])
            ->callMountedAction()
            ->assertHasActionErrors(['accountant_told']);
        $this->assertSame('trial', $this->company->fresh()->ergani_mode);
    }

    public function test_go_live_fails_closed_without_an_employer_afm(): void
    {
        $this->fake('0', '');   // ΕΡΓΑΝΗ returns no employer ΑΦΜ
        $this->assertFalse(app(ErganiGoLive::class)->checks($this->company->fresh())['connection']['ok'], 'no ΑΦΜ from ΕΡΓΑΝΗ → never «OK»');
    }

    public function test_an_empty_employee_afm_blocks_and_an_unsent_mail_is_reported(): void
    {
        $this->fake();
        $this->employeeFor(null)->forceFill(['afm' => ''])->save();
        $this->assertTrue(app(ErganiGoLive::class)->checks($this->company->fresh())['blocking'], 'an empty ΑΦΜ counts as missing');

        Employee::query()->update(['afm' => '123456783']);
        $this->mock(TenantMailerFactory::class, fn ($m) => $m->shouldReceive('for')->andThrow(new \RuntimeException('smtp down')));
        $result = app(ErganiGoLive::class)->goLive($this->company->fresh(), $this->makeUser(TenantRoleProvisioner::ROLE_COMPANY_ADMIN), true);
        $this->assertSame(['error' => null, 'mailed' => false], $result, 'switched, and the page is told the mail failed');
        $this->assertSame('production', $this->company->fresh()->ergani_mode);
    }

    public function test_a_restored_tenant_always_lands_in_trial_without_a_foreign_user(): void
    {
        $by = $this->makeUser(TenantRoleProvisioner::ROLE_COMPANY_ADMIN);
        $ref = new \ReflectionMethod(CompanyImporter::class, 'companyAttributes');
        $attrs = $ref->invoke(app(CompanyImporter::class),
            ['name' => 'X', 'ergani_mode' => 'production', 'ergani_production_since' => now()->toDateTimeString(), 'ergani_production_by_user_id' => $by->id], []);

        $this->assertSame(['trial', null, null], [$attrs['ergani_mode'], $attrs['ergani_production_since'], $attrs['ergani_production_by_user_id']]);
    }

    public function test_switches_are_recorded_in_the_activity_log(): void
    {
        Mail::fake();
        $this->fake();
        $by = $this->makeUser(TenantRoleProvisioner::ROLE_COMPANY_ADMIN);
        app(ErganiGoLive::class)->goLive($this->company->fresh(), $by, false);
        app(ErganiGoLive::class)->backToTrial($this->company->fresh(), $by);

        $log = Activity::query()->where('log_name', 'ergani')->where('company_id', $this->company->id)->pluck('description')->all();
        $this->assertSame(['ΕΡΓΑΝΗ: πέρασμα σε Παραγωγή', 'ΕΡΓΑΝΗ: επιστροφή σε Δοκιμαστικό'], $log);
    }
}
