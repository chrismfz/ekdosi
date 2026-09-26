<?php

namespace Tests\Feature\Hr;

use App\Enums\HolidayRule;
use App\Enums\LeaveStatus;
use App\Enums\LeaveType;
use App\Filament\Pages\LeaveCalendar;
use App\Models\CalendarFeed;
use App\Models\CompanyHoliday;
use App\Models\Lead;
use App\Models\LeaveRequest;
use App\Models\OvertimeDeclaration;
use App\Services\Hr\CalendarFeedBuilder;
use App\Services\TenantRoleProvisioner;
use App\Support\ErrorAlerts\ExceptionNotifier;
use Illuminate\Encryption\Encrypter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

/** «Το ημερολόγιό μου» — read-only ICS subscription per user per company. */
class CalendarFeedTest extends HrTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach (['ViewAny:Lead', 'View:Lead', 'View:LeadsCalendar'] as $p) {
            Permission::findOrCreate($p, 'web');
        }
        app(TenantRoleProvisioner::class)->ensureStandardRoles($this->company);
        $this->travelTo('2026-10-05 09:00:00');
    }

    private function ics(CalendarFeed $feed): string
    {
        return $this->get('/calendar/'.$feed->token.'.ics')->assertOk()
            ->assertHeader('Content-Type', 'text/calendar; charset=utf-8')->getContent();
    }

    public function test_opening_the_calendar_page_never_creates_a_link_only_the_modal_does(): void
    {
        $this->actAs($this->makeUser(TenantRoleProvisioner::ROLE_OPERATOR));
        Livewire::test(LeaveCalendar::class)->assertActionVisible('calendarFeed');
        $this->assertSame(0, CalendarFeed::query()->withoutGlobalScopes()->count(), 'a plain render creates no token');

        Livewire::test(LeaveCalendar::class)->mountAction('calendarFeed')
            ->assertMountedActionModalSee('/calendar/');
        $this->assertSame(1, CalendarFeed::query()->withoutGlobalScopes()->count());
    }

    public function test_feed_contents_follow_the_privacy_rules(): void
    {
        $me = $this->makeUser(TenantRoleProvisioner::ROLE_OPERATOR);
        $mine = $this->employeeFor($me, 'Εγώ', 'Χρήστος');
        $colleague = $this->employeeFor(null, 'Συνάδελφος', 'Γιάννης');
        LeaveRequest::create(['company_id' => $this->company->id, 'employee_id' => $mine->id, 'type' => LeaveType::Annual,
            'starts_on' => '2026-10-12', 'ends_on' => '2026-10-14', 'days' => 3, 'status' => LeaveStatus::Pending]);
        LeaveRequest::create(['company_id' => $this->company->id, 'employee_id' => $colleague->id, 'type' => LeaveType::Sick,
            'starts_on' => '2026-10-07', 'ends_on' => '2026-10-07', 'days' => 1, 'status' => LeaveStatus::Approved]);
        LeaveRequest::create(['company_id' => $this->company->id, 'employee_id' => $colleague->id, 'type' => LeaveType::Annual,
            'starts_on' => '2026-11-02', 'ends_on' => '2026-11-02', 'days' => 1, 'status' => LeaveStatus::Pending]);
        CompanyHoliday::create(['company_id' => $this->company->id, 'name' => 'Άγιος Τοπικός', 'rule' => HolidayRule::Fixed, 'month' => 10, 'day' => 20, 'is_active' => true]);
        Lead::create(['company_id' => $this->company->id, 'name' => 'Πελάτης Χ, ΑΕ', 'assigned_user_id' => $me->id,
            'next_action_at' => '2026-10-12 10:00:00', 'status' => 'contacted']);
        Lead::create(['company_id' => $this->company->id, 'name' => 'Άλλου lead', 'next_action_at' => '2026-10-12 11:00:00', 'status' => 'contacted']);
        $ot = OvertimeDeclaration::create(['company_id' => $this->company->id, 'employee_id' => $mine->id, 'work_date' => '2026-10-06', 'from_time' => '18:00', 'to_time' => '20:00']);
        $ot->forceFill(['ergani_status' => 'submitted'])->saveQuietly();

        $ics = $this->ics(CalendarFeed::for($me, $this->company));

        $this->assertStringContainsString("BEGIN:VCALENDAR\r\n", $ics);
        $this->assertStringContainsString('SUMMARY:Άδεια: Κανονική άδεια (σε αναμονή)', $ics);
        $this->assertStringContainsString("DTSTART;VALUE=DATE:20261012\r\nDTEND;VALUE=DATE:20261015", $ics);   // DTEND exclusive
        $this->assertStringContainsString('SUMMARY:Άδεια — Συνάδελφος Γιάννης', $ics);
        $this->assertStringNotContainsString('ασθένειας', $ics, 'a colleague\'s leave type never leaves ekdosi');
        $this->assertStringNotContainsString('20261102', $ics, 'a colleague\'s PENDING leave is not shown');
        $this->assertStringContainsString('SUMMARY:Αργία: Άγιος Τοπικός', $ics);
        $this->assertStringContainsString('SUMMARY:Lead: Πελάτης Χ\\, ΑΕ — επόμενο βήμα', $ics, 'RFC 5545 escaping');
        $this->assertStringContainsString('DTSTART:20261012T070000Z', $ics, '10:00 Athens = 07:00Z');
        $this->assertStringNotContainsString('Άλλου lead', $ics, 'only MY leads');
        $this->assertStringContainsString("SUMMARY:Υπερωρία\r\n", $ics);
        foreach (explode("\r\n", $ics) as $line) {
            $this->assertLessThanOrEqual(75, strlen($line), 'folded at 75 octets');
        }
    }

    public function test_toggles_and_rights_are_applied_on_every_fetch(): void
    {
        $me = $this->makeUser(TenantRoleProvisioner::ROLE_OPERATOR);
        $this->employeeFor($me, 'Εγώ', 'Χρήστος');
        Lead::create(['company_id' => $this->company->id, 'name' => 'Πελάτης Ψ', 'assigned_user_id' => $me->id,
            'next_action_at' => '2026-10-12 10:00:00', 'status' => 'contacted']);
        $feed = CalendarFeed::for($me, $this->company);

        $feed->forceFill(['include_leads' => false, 'include_holidays' => false])->save();
        $ics = $this->ics($feed);
        $this->assertStringNotContainsString('Πελάτης Ψ', $ics);
        $this->assertStringNotContainsString('Αργία', $ics);

        // ergani staff (no Lead rights): leads never appear, even if toggled on.
        $staff = $this->makeUser(TenantRoleProvisioner::ROLE_ERGANI);
        Lead::create(['company_id' => $this->company->id, 'name' => 'Πελάτης Ω', 'assigned_user_id' => $staff->id,
            'next_action_at' => '2026-10-12 10:00:00', 'status' => 'contacted']);
        $this->assertStringNotContainsString('Πελάτης Ω', $this->ics(CalendarFeed::for($staff, $this->company)));
    }

    public function test_unknown_rotated_revoked_or_removed_links_are_404(): void
    {
        $me = $this->makeUser(TenantRoleProvisioner::ROLE_OPERATOR);
        $feed = CalendarFeed::for($me, $this->company);
        $old = $feed->token;

        $this->get('/calendar/'.str_repeat('a', 48).'.ics')->assertNotFound();

        $feed->rotate();
        $this->get('/calendar/'.$old.'.ics')->assertNotFound();
        $this->get('/calendar/'.$feed->token.'.ics')->assertOk();

        $me->companies()->detach($this->company->id);
        $token = $feed->token;
        $this->get('/calendar/'.$token.'.ics')->assertNotFound();

        $me->companies()->attach($this->company->id);
        $this->get('/calendar/'.$token.'.ics')->assertNotFound('a removed user\'s link never comes back on re-add');
        $this->assertNull(CalendarFeed::query()->withoutGlobalScopes()->find($feed->id));
    }

    public function test_long_lines_fold_on_utf8_boundaries(): void
    {
        $folded = CalendarFeedBuilder::fold('SUMMARY:'.str_repeat('Άδεια ', 30));
        foreach (explode("\r\n", $folded) as $i => $line) {
            $this->assertLessThanOrEqual(75, strlen($line));
            $this->assertTrue(mb_check_encoding($line, 'UTF-8'), 'never splits a character');
            if ($i > 0) {
                $this->assertStringStartsWith(' ', $line);
            }
        }
    }

    public function test_rotate_and_revoke_from_the_modal_only_touch_my_own_link(): void
    {
        $other = CalendarFeed::for($this->makeUser(TenantRoleProvisioner::ROLE_OPERATOR), $this->company);
        $me = $this->actAs($this->makeUser(TenantRoleProvisioner::ROLE_OPERATOR));
        $feed = CalendarFeed::for($me, $this->company);
        $old = $feed->token;

        Livewire::test(LeaveCalendar::class)->callAction([['name' => 'calendarFeed'], ['name' => 'rotateCalendarFeed']]);
        $this->assertNotSame($old, $feed->fresh()->token);

        Livewire::test(LeaveCalendar::class)->callAction([['name' => 'calendarFeed'], ['name' => 'revokeCalendarFeed']]);
        $this->assertNull(CalendarFeed::query()->withoutGlobalScopes()->find($feed->id));
        $this->assertNotNull(CalendarFeed::query()->withoutGlobalScopes()->find($other->id), 'someone else\'s link untouched');
    }

    public function test_polls_create_no_session_and_ex_employees_drop_out(): void
    {
        $me = $this->makeUser(TenantRoleProvisioner::ROLE_OPERATOR);
        $gone = $this->employeeFor(null, 'Πρώην', 'Υπάλληλος');
        LeaveRequest::create(['company_id' => $this->company->id, 'employee_id' => $gone->id, 'type' => LeaveType::Annual,
            'starts_on' => '2026-10-07', 'ends_on' => '2026-10-07', 'days' => 1, 'status' => LeaveStatus::Approved]);
        $gone->delete();

        $response = $this->get('/calendar/'.CalendarFeed::for($me, $this->company)->token.'.ics')->assertOk();
        $this->assertStringNotContainsString('Πρώην', $response->getContent());
        $this->assertEmpty($response->headers->getCookies(), 'no Set-Cookie for a calendar poll');
    }

    public function test_the_token_is_redacted_from_error_alert_context(): void
    {
        $token = CalendarFeed::for($this->makeUser(TenantRoleProvisioner::ROLE_OPERATOR), $this->company)->token;
        $this->app->instance('request', Request::create('/calendar/'.$token.'.ics'));
        $ctx = (new \ReflectionMethod(ExceptionNotifier::class, 'currentContext'))
            ->invoke(app(ExceptionNotifier::class));
        // The test runs in console, so the HTTP branch is exercised directly:
        $this->assertStringNotContainsString($token, $ctx);
    }

    public function test_an_undecryptable_token_after_an_app_key_change_issues_a_new_link(): void
    {
        $feed = CalendarFeed::for($this->makeUser(TenantRoleProvisioner::ROLE_OPERATOR), $this->company);
        $oldHash = $feed->token_hash;
        DB::table('calendar_feeds')->where('id', $feed->id)
            ->update(['token' => (new Encrypter(random_bytes(32), 'AES-256-CBC'))->encrypt('x')]);   // another key

        $url = CalendarFeed::query()->withoutGlobalScopes()->find($feed->id)->url();

        $this->assertStringContainsString('/calendar/', $url);
        $this->assertNotSame($oldHash, $feed->fresh()->token_hash, 'a fresh link was issued');
        $this->get(parse_url($url, PHP_URL_PATH))->assertOk();
    }

    public function test_admins_see_all_leads_and_pending_team_leaves_but_never_the_type(): void
    {
        $admin = $this->makeUser(TenantRoleProvisioner::ROLE_COMPANY_ADMIN);
        $op = $this->makeUser(TenantRoleProvisioner::ROLE_OPERATOR);
        $op->forceFill(['name' => 'Ηλίας Χειριστής'])->save();
        $colleague = $this->employeeFor(null, 'Συνάδελφος', 'Γιάννης');
        LeaveRequest::create(['company_id' => $this->company->id, 'employee_id' => $colleague->id, 'type' => LeaveType::Sick,
            'starts_on' => '2026-11-02', 'ends_on' => '2026-11-02', 'days' => 1, 'status' => LeaveStatus::Pending]);
        Lead::create(['company_id' => $this->company->id, 'name' => 'Πελάτης του Ηλία', 'assigned_user_id' => $op->id,
            'next_action_at' => '2026-10-12 10:00:00', 'status' => 'contacted']);

        $adminFeed = CalendarFeed::for($admin, $this->company);
        $this->assertTrue($adminFeed->include_all_leads, 'admins get the whole picture by default');
        $ics = str_replace("\r\n ", '', $this->ics($adminFeed));   // unfold (RFC 5545 §3.1) — calendar apps do the same
        $this->assertStringContainsString('SUMMARY:Lead: Πελάτης του Ηλία — επόμενο βήμα (Ηλίας Χειριστής)', $ics);
        $this->assertStringContainsString('SUMMARY:Άδεια (σε αναμονή) — Συνάδελφος Γιάννης', $ics);
        $this->assertStringNotContainsString('ασθένειας', $ics, 'the type never leaves ekdosi — not even for admins');

        $opFeed = CalendarFeed::for($this->makeUser(TenantRoleProvisioner::ROLE_OPERATOR), $this->company);
        $this->assertFalse($opFeed->include_all_leads);
        $ics = str_replace("\r\n ", '', $this->ics($opFeed));
        $this->assertStringNotContainsString('Πελάτης του Ηλία', $ics, 'an operator sees only their own leads by default');
        $this->assertStringNotContainsString('σε αναμονή) — Συνάδελφος', $ics, 'non-approvers never see pending colleagues');
    }

    public function test_the_calendar_pages_offer_the_second_entry_link(): void
    {
        $this->actAs($this->makeUser(TenantRoleProvisioner::ROLE_OPERATOR));
        Livewire::test(LeaveCalendar::class)->assertSee('Βάλε αυτό το ημερολόγιο στο κινητό σου')->assertSeeHtml("mountAction('calendarFeed')");
    }
}
