<?php

namespace Tests\Feature\Hr;

use App\Enums\LeaveStatus;
use App\Enums\LeaveType;
use App\Filament\Pages\LeaveCalendar;
use App\Filament\Widgets\ErganiStatusWidget;
use App\Models\Employee;
use App\Models\ErganiSubmission;
use App\Models\LeaveRequest;
use App\Models\OvertimeDeclaration;
use App\Models\User;
use App\Services\Ergani\ErganiClient;
use App\Services\TenantRoleProvisioner;
use App\Support\Hr\ErganiTrialBar;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

/** Overtime on the calendar, the «ΕΡΓΑΝΗ» dashboard card, the trial bar, the card-sector watch. */
class ErganiVisibilityTest extends HrTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach (['ViewAny', 'View', 'Create', 'Update'] as $a) {
            Permission::findOrCreate("{$a}:OvertimeDeclaration", 'web');
        }
        app(TenantRoleProvisioner::class)->ensureStandardRoles($this->company);
        $this->company->forceFill(['ergani_mode' => 'trial', 'ergani_username' => 'U', 'ergani_password' => 'P'])->save();
        Cache::flush();
        $this->travelTo('2026-10-05 09:00:00');
    }

    private function overtime(Employee $e, string $date, string $from, string $to, ?string $status, ?string $env = 'trial'): OvertimeDeclaration
    {
        $o = OvertimeDeclaration::create(['company_id' => $this->company->id, 'employee_id' => $e->id, 'work_date' => $date, 'from_time' => $from, 'to_time' => $to]);
        $o->forceFill(['ergani_status' => $status, 'ergani_env' => $env, 'ergani_protocol' => $status === 'submitted' ? 'ΑΚ - ΟΡ1' : null])->saveQuietly();

        return $o;
    }

    public function test_calendar_shows_overtime_to_admins_and_to_the_employee_only(): void
    {
        $meUser = $this->makeUser(TenantRoleProvisioner::ROLE_ERGANI);
        $me = $this->employeeFor($meUser);
        $colleague = $this->employeeFor(null, 'Συνάδελφος', 'Γιάννης');
        $this->overtime($me, '2026-10-06', '18:00', '20:30', 'submitted');
        $this->overtime($colleague, '2026-10-07', '18:00', '19:00', 'failed');
        $this->overtime($colleague, '2026-10-08', '18:00', '19:00', 'superseded');

        $this->actAs($this->makeUser(TenantRoleProvisioner::ROLE_COMPANY_ADMIN));
        $rows = collect(Livewire::test(LeaveCalendar::class)->set('month', '2026-10')->viewData('rows'))->keyBy('id');
        $this->assertSame(['+2:30ω', false], [$rows[$me->id]['ot']['2026-10-06']['label'], $rows[$me->id]['ot']['2026-10-06']['warn']]);
        $this->assertStringContainsString('πρωτ. ΑΚ - ΟΡ1', $rows[$me->id]['ot']['2026-10-06']['title']);
        $this->assertTrue($rows[$colleague->id]['ot']['2026-10-07']['warn'], 'not declared → warn');
        $this->assertArrayNotHasKey('2026-10-08', $rows[$colleague->id]['ot'], 'superseded attempts are not shown');

        $this->actAs($meUser);
        $rows = collect(Livewire::test(LeaveCalendar::class)->set('month', '2026-10')->viewData('rows'))->keyBy('id');
        $this->assertArrayHasKey('2026-10-06', $rows[$me->id]['ot']);
        $this->assertSame([], $rows[$colleague->id]['ot'], 'a colleague\'s overtime stays private');
    }

    public function test_status_card_lists_what_needs_attention_and_is_admin_only(): void
    {
        $this->company->forceFill(['ergani_submit_leaves' => true, 'ergani_submit_overtime' => true])->save();
        $this->actAs($this->makeUser(TenantRoleProvisioner::ROLE_COMPANY_ADMIN));
        Livewire::test(ErganiStatusWidget::class)->assertSee('Τίποτα εκκρεμές')->assertSee('ΔΟΚΙΜΑΣΤΙΚΟ');

        $e = $this->employeeFor(null);
        LeaveRequest::create(['company_id' => $this->company->id, 'employee_id' => $e->id, 'type' => LeaveType::Annual,
            'starts_on' => '2026-10-06', 'ends_on' => '2026-10-07', 'days' => 2, 'status' => LeaveStatus::Approved]);   // tomorrow, not declared
        $failed = LeaveRequest::create(['company_id' => $this->company->id, 'employee_id' => $e->id, 'type' => LeaveType::Annual,
            'starts_on' => '2026-11-02', 'ends_on' => '2026-11-02', 'days' => 1, 'status' => LeaveStatus::Approved]);
        $failed->forceFill(['ergani_status' => 'unknown'])->saveQuietly();
        $this->overtime($e, '2026-10-05', '18:00', '20:00', 'failed');
        ErganiSubmission::create(['company_id' => $this->company->id, 'document' => 'WTOOv', 'action' => 'submit', 'environment' => 'trial', 'ok' => true]);

        Livewire::test(ErganiStatusWidget::class)
            ->assertSee('1 άδεια με αποτυχημένη ή αβέβαιη δήλωση')
            ->assertSee('ξεκινά/ούν έως μεθαύριο ΧΩΡΙΣ δήλωση')
            ->assertSee('1 επερχόμενη/ες υπερωρία/ες')
            ->assertSee('Σήμερα 18:00–20:00')
            ->assertSee('WTOOv');

        $this->actAs($this->makeUser(TenantRoleProvisioner::ROLE_OPERATOR));
        $this->assertFalse(ErganiStatusWidget::canView());
    }

    public function test_trial_bar_only_in_trial_with_something_auto_declared(): void
    {
        $this->actAs($this->makeUser(TenantRoleProvisioner::ROLE_COMPANY_ADMIN));
        $this->assertSame('', ErganiTrialBar::html(), 'nothing auto-declared → no bar');

        $this->company->forceFill(['ergani_submit_leaves' => true])->save();
        $this->assertStringContainsString('ΔΟΚΙΜΑΣΤΙΚΟ ΕΡΓΑΝΗ', ErganiTrialBar::html());

        $this->company->forceFill(['ergani_mode' => 'production'])->save();
        $this->assertSame('', ErganiTrialBar::html());
    }

    public function test_card_sector_watch_reads_production_stores_and_bells_once_on_the_flip(): void
    {
        $admin = $this->makeUser(TenantRoleProvisioner::ROLE_COMPANY_ADMIN);
        $urls = [];
        $sector = '0';
        Http::fake(function (Request $r) use (&$urls, &$sector) {
            $urls[] = $r->url();

            return str_ends_with($r->url(), '/Authentication')
                ? Http::response(['accessToken' => 'tok'])
                : Http::response(['EX_BASE_01' => ['Ergodotis' => ['Afm' => '800561849', 'Eponimia' => 'X', 'IsInCardSector' => $sector]]]);
        });

        $this->artisan('ergani:watch')->assertSuccessful();
        $this->assertFalse($this->company->fresh()->ergani_card_sector);
        $this->assertNotNull($this->company->fresh()->ergani_card_sector_checked_at);
        foreach ($urls as $u) {
            $this->assertStringStartsWith(ErganiClient::PRODUCTION_URL, $u);
        }
        $this->assertSame('trial', $this->company->fresh()->ergani_mode, 'mode never changed');
        $this->assertSame(0, $admin->notifications()->count());

        $sector = '1';
        $this->artisan('ergani:watch')->assertSuccessful();
        $this->artisan('ergani:watch')->assertSuccessful();
        $this->assertTrue($this->company->fresh()->ergani_card_sector);
        $this->assertSame(1, User::find($admin->id)->notifications()->count(), 'one bell, on the flip only');
    }
}
