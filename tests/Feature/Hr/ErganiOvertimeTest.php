<?php

namespace Tests\Feature\Hr;

use App\Filament\Resources\OvertimeDeclarations\OvertimeDeclarationResource;
use App\Filament\Resources\OvertimeDeclarations\Pages\ListOvertimeDeclarations;
use App\Mail\OvertimeAccountantMail;
use App\Models\Employee;
use App\Models\ErganiSubmission;
use App\Models\OvertimeDeclaration;
use App\Services\Ergani\OvertimeRefused;
use App\Services\Ergani\OvertimeService;
use App\Services\TenantRoleProvisioner;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

class ErganiOvertimeTest extends HrTestCase
{
    /** @var list<array<string, mixed>> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['ViewAny', 'View', 'Create', 'Update'] as $a) {
            Permission::findOrCreate("{$a}:OvertimeDeclaration", 'web');
        }
        app(TenantRoleProvisioner::class)->ensureStandardRoles($this->company);
        $this->company->forceFill(['afm' => '800561849', 'ergani_submit_overtime' => true, 'ergani_mode' => 'trial',
            'ergani_username' => 'U', 'ergani_password' => 'P'])->save();
        Cache::flush();
        $this->travelTo('2026-09-28 10:00:00');   // Monday morning, Athens (app tz)
        Mail::fake();
    }

    private function fake(int $status = 200, array $body = [['id' => '1', 'protocol' => 'ΑΚ - ΟΡ685661', 'submitDate' => '28/09/2026 10:00']]): void
    {
        Http::fake(function (Request $r) use ($status, $body) {
            if (str_ends_with($r->url(), '/Authentication')) {
                return Http::response(['accessToken' => 'tok']);
            }
            $this->sent[] = $r->data();

            return Http::response($body, $status);
        });
    }

    private function employee(): Employee
    {
        return Employee::create(['company_id' => $this->company->id, 'last_name' => 'Παπαδόπουλος', 'first_name' => 'Ηλίας',
            'afm' => '123456783', 'ergani_branch' => 0]);
    }

    public function test_declares_with_the_trial_verified_payload_and_tells_the_accountant(): void
    {
        $this->fake();
        $o = app(OvertimeService::class)->declare($this->employee(), '2026-09-28', '18:00', '20:00', null, 'ιδιωτική σημείωση');

        $this->assertSame(['submitted', 'ΑΚ - ΟΡ685661', 'trial'], [$o->ergani_status, $o->ergani_protocol, $o->ergani_env]);
        $wto = $this->sent[0]['WTOS']['WTO'][0];
        $emp = $wto['Ergazomenoi']['ErgazomenoiWTO'][0];
        $this->assertSame(['0', '28/09/2026', '28/09/2026'], [$wto['f_aa_pararthmatos'], $wto['f_from_date'], $wto['f_to_date']]);
        $this->assertSame(['123456783', 'ΠΑΠΑΔΟΠΟΥΛΟΣ', 'ΗΛΙΑΣ', '28/09/2026'], [$emp['f_afm'], $emp['f_eponymo'], $emp['f_onoma'], $emp['f_date']]);
        $this->assertSame([['f_type' => 'ΥΠ', 'f_from' => '18:00', 'f_to' => '20:00']], $emp['ErgazomenosAnalytics']['ErgazomenosWTOAnalytics']);
        $this->assertStringNotContainsString('ιδιωτική', json_encode($this->sent[0], JSON_UNESCAPED_UNICODE), 'the internal note never goes to ΕΡΓΑΝΗ');
        $this->assertSame(1, ErganiSubmission::query()->where('overtime_declaration_id', $o->id)->where('ok', true)->count());
        Mail::assertSent(OvertimeAccountantMail::class, fn (OvertimeAccountantMail $m) => $m->hasTo('accountant@example.test'));
        $this->assertSame('28/09/2026 18:00–20:00 (2ω)', $o->slotLabel());
    }

    public function test_a_slot_that_already_started_is_refused_before_calling_ergani(): void
    {
        $this->fake();
        $e = $this->employee();
        foreach ([['2026-09-28', '09:00', '11:00'], ['2026-09-27', '18:00', '20:00'], ['2026-09-28', '18:00', '17:00']] as [$d, $f, $t]) {
            try {
                app(OvertimeService::class)->declare($e, $d, $f, $t, null);
                $this->fail("expected refusal for {$d} {$f}-{$t}");
            } catch (OvertimeRefused) {
            }
        }
        $this->assertSame([], $this->sent);
        $this->assertSame(0, OvertimeDeclaration::count());
    }

    public function test_overlapping_hours_are_refused(): void
    {
        $this->fake();
        $e = $this->employee();
        app(OvertimeService::class)->declare($e, '2026-09-28', '18:00', '20:00', null);

        $this->expectException(OvertimeRefused::class);
        app(OvertimeService::class)->declare($e, '2026-09-28', '19:00', '21:00', null);
    }

    public function test_a_definite_rejection_is_failed_and_the_accountant_is_told(): void
    {
        $this->fake(400, ['message' => 'Η υποβολή σας θεωρείται εκπρόθεσμη.']);
        $failed = app(OvertimeService::class)->declare($this->employee(), '2026-09-28', '18:00', '19:00', null);
        $this->assertSame('failed', $failed->ergani_status);
        Mail::assertSent(OvertimeAccountantMail::class, fn (OvertimeAccountantMail $m) => str_contains($m->envelope()->subject, 'ΧΩΡΙΣ δήλωση'));
    }

    public function test_a_timeout_is_unknown_and_is_not_resent_without_confirmation(): void
    {
        Http::fake(fn (Request $r) => str_ends_with($r->url(), '/Authentication') ? Http::response(['accessToken' => 'tok']) : Http::response('oops', 503));
        $e2 = Employee::create(['company_id' => $this->company->id, 'last_name' => 'Β', 'first_name' => 'Β', 'afm' => '234567897']);
        $unknown = app(OvertimeService::class)->declare($e2, '2026-09-28', '18:00', '19:00', null);
        $this->assertSame('unknown', $unknown->ergani_status);
        $this->assertFalse(app(OvertimeService::class)->submit($unknown), 'an unknown one is not re-sent without confirmation');
        $this->assertSame('unknown', $unknown->fresh()->ergani_status);
    }

    public function test_admin_declares_from_the_page_and_operators_cannot_reach_it(): void
    {
        $this->fake();
        $this->actAs($this->makeUser(TenantRoleProvisioner::ROLE_COMPANY_ADMIN));
        $e = $this->employee();

        Livewire::test(ListOvertimeDeclarations::class)
            ->callAction('declareOvertime', data: ['employee_id' => $e->id, 'work_date' => '2026-09-28', 'from_time' => '18:00', 'to_time' => '20:30'])
            ->assertHasNoActionErrors()
            ->assertNotified();
        $this->assertSame('submitted', OvertimeDeclaration::query()->sole()->ergani_status);

        // Opt-in off → no «Νέα υπερωρία».
        $this->company->forceFill(['ergani_submit_overtime' => false])->save();
        Livewire::test(ListOvertimeDeclarations::class)->assertActionHidden('declareOvertime');

        $this->actAs($this->makeUser(TenantRoleProvisioner::ROLE_OPERATOR));
        $this->assertFalse(OvertimeDeclarationResource::canAccess());
        $this->actAs($this->makeUser(TenantRoleProvisioner::ROLE_ERGANI));
        $this->assertFalse(OvertimeDeclarationResource::canAccess());
    }
}
