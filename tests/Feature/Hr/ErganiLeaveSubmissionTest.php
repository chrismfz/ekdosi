<?php

namespace Tests\Feature\Hr;

use App\Enums\LeaveStatus;
use App\Enums\LeaveType;
use App\Filament\Resources\LeaveRequests\Pages\ViewLeaveRequest;
use App\Mail\LeaveAccountantMail;
use App\Models\Employee;
use App\Models\ErganiSubmission;
use App\Models\LeaveRequest;
use App\Services\Ergani\ErganiClient;
use App\Services\Ergani\LeaveErganiSubmitter;
use App\Services\Hr\LeaveWorkflow;
use App\Services\TenantRoleProvisioner;
use App\Support\Hr\WorkingDays;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

class ErganiLeaveSubmissionTest extends HrTestCase
{
    private function enable(string $mode = 'trial'): void
    {
        $this->company->forceFill([
            'ergani_submit_leaves' => true, 'ergani_mode' => $mode,
            'ergani_username' => 'EFKA1', 'ergani_password' => 'pw',
        ])->save();
        Cache::flush();
    }

    private function fakeErgani(int $submitStatus = 200, array $submitBody = [['id' => '92', 'protocol' => 'ΕΥΣ92', 'submitDate' => '02/10/2026 10:15']]): void
    {
        Http::fake([
            '*/Authentication' => Http::response(['accessToken' => 'tok'], 200),
            '*/Documents/CancelSubmittedDocument' => Http::response(['message' => 'Η ακύρωση ολοκληρώθηκε επιτυχώς'], 200),
            '*/Documents/WTOLeave' => Http::response($submitBody, $submitStatus),
        ]);
    }

    private function approvedLeave(?Employee $e = null, string $from = '2026-10-02', string $to = '2026-10-05'): LeaveRequest
    {
        $e ??= Employee::create(['company_id' => $this->company->id, 'last_name' => 'Παπαδόπουλος', 'first_name' => 'Ηλίας',
            'afm' => '123456783', 'annual_leave_days' => 20]);
        $leave = LeaveRequest::create(['company_id' => $this->company->id, 'employee_id' => $e->id, 'type' => LeaveType::Annual,
            'starts_on' => $from, 'ends_on' => $to,
            'days' => WorkingDays::for($this->company->id)->count(CarbonImmutable::parse($from), CarbonImmutable::parse($to))]);

        return app(LeaveWorkflow::class)->approve($leave, $this->makeUser(TenantRoleProvisioner::ROLE_COMPANY_ADMIN));
    }

    public function test_approval_declares_each_working_day_and_emails_the_protocol(): void
    {
        Mail::fake();
        $this->enable();
        $this->fakeErgani();

        // Fri 2/10 – Mon 5/10/2026: the weekend is not declared.
        $leave = $this->approvedLeave();

        $this->assertSame('submitted', $leave->ergani_status);
        $this->assertSame('ΕΥΣ92', $leave->ergani_protocol);
        $this->assertSame('trial', $leave->ergani_env);
        $this->assertSame('2026-10-02 10:15', $leave->ergani_submitted_at->format('Y-m-d H:i'));

        Http::assertSent(function (Request $r): bool {
            if (! str_ends_with($r->url(), '/Documents/WTOLeave')) {
                return false;
            }
            $wto = $r['WTOS']['WTO'][0];
            $days = $wto['Ergazomenoi']['ErgazomenoiWTO'];

            return str_starts_with($r->url(), ErganiClient::TRIAL_URL)
                && $wto['f_from_date'] === '02/10/2026' && $wto['f_to_date'] === '05/10/2026'
                && array_column($days, 'f_date') === ['02/10/2026', '05/10/2026']
                && $days[0]['f_eponymo'] === 'ΠΑΠΑΔΟΠΟΥΛΟΣ' && $days[0]['f_onoma'] === 'ΗΛΙΑΣ'
                && $days[0]['ErgazomenosAnalytics']['ErgazomenosWTOAnalytics'][0] === [
                    'f_type' => 'ΑΔΚΑΝ', 'f_from' => '', 'f_to' => '', 'f_year' => '2026', 'f_req_days' => '020',
                ];
        });

        $audit = ErganiSubmission::query()->sole();
        $this->assertTrue($audit->ok);
        $this->assertSame('submit', $audit->action);
        $this->assertSame('ΕΥΣ92', $audit->protocol);

        Mail::assertSent(LeaveAccountantMail::class, function (LeaveAccountantMail $m): bool {
            return str_contains((string) $m->erganiLine(), 'ΕΥΣ92') && str_contains((string) $m->erganiLine(), 'ΔΟΚΙΜΑΣΤΙΚΟ')
                && str_contains($m->intro(), 'παρακαλούμε για τη δήλωσή'); // trial = void → still ask
        });
    }

    public function test_production_declaration_tells_the_accountant_no_action_needed(): void
    {
        Mail::fake();
        $this->enable('production');
        $this->fakeErgani();

        $leave = $this->approvedLeave();

        $this->assertSame('production', $leave->ergani_env);
        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), ErganiClient::PRODUCTION_URL.'/Documents/WTOLeave'));
        Mail::assertSent(LeaveAccountantMail::class, fn (LeaveAccountantMail $m) => str_contains($m->intro(), 'δεν χρειάζεται νέα δήλωση'));
    }

    public function test_rejection_by_ergani_is_recorded_and_the_approval_stands(): void
    {
        Mail::fake();
        $this->enable();
        $this->fakeErgani(400, ['message' => 'Δεν υπάρχει σχέση εργασίας στις ημερομηνίες που δηλώθηκαν']);

        $leave = $this->approvedLeave();

        $this->assertSame(LeaveStatus::Approved, $leave->status);
        $this->assertSame('failed', $leave->ergani_status);
        $this->assertStringContainsString('σχέση εργασίας', $leave->ergani_error);
        $this->assertFalse(ErganiSubmission::query()->sole()->ok);
        Mail::assertSent(LeaveAccountantMail::class, fn (LeaveAccountantMail $m) => str_contains($m->intro(), 'ΑΠΕΤΥΧΕ'));
    }

    public function test_nothing_is_sent_unless_the_tenant_opted_in(): void
    {
        Mail::fake();
        Http::fake();
        $this->company->forceFill(['ergani_username' => 'EFKA1', 'ergani_password' => 'pw', 'ergani_submit_leaves' => false])->save();

        $leave = $this->approvedLeave();

        $this->assertNull($leave->ergani_status);
        Http::assertNothingSent();
    }

    public function test_missing_afm_fails_without_calling_ergani(): void
    {
        Mail::fake();
        $this->enable();
        Http::fake();
        $e = Employee::create(['company_id' => $this->company->id, 'last_name' => 'Χωρίς', 'first_name' => 'Αφμ']);

        $leave = $this->approvedLeave($e);

        $this->assertSame('failed', $leave->ergani_status);
        $this->assertStringContainsString('ΑΦΜ', $leave->ergani_error);
        Http::assertNothingSent();
        $this->assertStringContainsString('Δεν στάλθηκε', ErganiSubmission::query()->sole()->message, 'refusals are audited too');
    }

    public function test_revocation_withdraws_in_the_environment_it_was_declared_in(): void
    {
        Mail::fake();
        $this->enable('trial');
        $this->fakeErgani();
        $leave = $this->approvedLeave();

        // The tenant later switches to production — the withdrawal must still go to trial.
        $this->company->forceFill(['ergani_mode' => 'production'])->save();
        $cancelled = app(LeaveWorkflow::class)->cancel($leave->fresh(), $this->makeUser(TenantRoleProvisioner::ROLE_COMPANY_ADMIN));

        $this->assertSame('cancelled', $cancelled->ergani_status);
        Http::assertSent(fn (Request $r) => $r->url() === ErganiClient::TRIAL_URL.'/Documents/CancelSubmittedDocument'
            && $r['TypeOfDocument'] === LeaveErganiSubmitter::CANCEL_TYPE
            && $r['Protocol'] === 'ΕΥΣ92' && $r['SubmittedDate'] === '20261002');
        $this->assertSame(['submit', 'cancel'], ErganiSubmission::query()->orderBy('id')->pluck('action')->all());
    }

    public function test_a_revocation_racing_the_declaration_still_withdraws_it(): void
    {
        Mail::fake();
        $this->enable();
        $admin = $this->makeUser(TenantRoleProvisioner::ROLE_COMPANY_ADMIN);
        $e = Employee::create(['company_id' => $this->company->id, 'last_name' => 'Α', 'first_name' => 'Β', 'afm' => '123456783']);
        $leave = LeaveRequest::create(['company_id' => $this->company->id, 'employee_id' => $e->id, 'type' => LeaveType::Annual,
            'starts_on' => '2026-10-05', 'ends_on' => '2026-10-05', 'days' => 1]);

        Http::fake([
            '*/Authentication' => Http::response(['accessToken' => 'tok'], 200),
            '*/Documents/CancelSubmittedDocument' => Http::response(['message' => 'ok'], 200),
            // While ΕΡΓΑΝΗ is processing the declaration, another approver revokes the leave.
            '*/Documents/WTOLeave' => function () use ($leave) {
                LeaveRequest::query()->whereKey($leave->id)->update(['status' => LeaveStatus::Cancelled->value]);

                return Http::response([['id' => '1', 'protocol' => 'ΕΥΣ1', 'submitDate' => '05/10/2026 09:00']], 200);
            },
        ]);

        app(LeaveWorkflow::class)->approve($leave, $admin);

        $this->assertSame('cancelled', $leave->fresh()->ergani_status);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/Documents/CancelSubmittedDocument'));
    }

    public function test_token_is_reused_across_calls(): void
    {
        Mail::fake();
        $this->enable();
        $this->fakeErgani();

        $first = $this->approvedLeave();
        $this->approvedLeave($first->employee, '2026-10-12', '2026-10-12');

        $this->assertCount(1, Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/Authentication')));
    }

    public function test_a_leave_is_declared_once_even_if_submit_is_triggered_again(): void
    {
        Mail::fake();
        $this->enable();
        $this->fakeErgani();
        $leave = $this->approvedLeave();

        // Double-click / second tab / retry after success: nothing more goes out.
        $this->assertFalse(app(LeaveErganiSubmitter::class)->submit($leave->fresh()));
        $this->assertCount(1, Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/Documents/WTOLeave')));
    }

    public function test_a_concurrent_claim_blocks_a_second_submitter(): void
    {
        Mail::fake();
        $this->enable();
        $this->fakeErgani();
        $e = Employee::create(['company_id' => $this->company->id, 'last_name' => 'Α', 'first_name' => 'Β', 'afm' => '123456783']);
        $leave = LeaveRequest::create(['company_id' => $this->company->id, 'employee_id' => $e->id, 'type' => LeaveType::Annual,
            'starts_on' => '2026-10-05', 'ends_on' => '2026-10-05', 'days' => 1]);
        $leave->forceFill(['status' => LeaveStatus::Approved])->save();
        // Another request is mid-flight (fresh claim).
        LeaveRequest::query()->whereKey($leave->id)->update(['ergani_status' => 'submitting', 'updated_at' => now()]);

        $this->assertFalse(app(LeaveErganiSubmitter::class)->submit($leave->fresh()));
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/Documents/WTOLeave'));

        // …a claim left behind by a crashed attempt may have landed too: only
        // with the operator's confirmation can it be taken over.
        LeaveRequest::query()->whereKey($leave->id)->update(['updated_at' => now()->subMinutes(LeaveErganiSubmitter::CLAIM_STALE_MINUTES + 1)]);
        $this->assertFalse(app(LeaveErganiSubmitter::class)->submit($leave->fresh()));
        $this->assertTrue(app(LeaveErganiSubmitter::class)->submit($leave->fresh(), null, confirmed: 'unknown'));
    }

    public function test_a_timeout_after_sending_is_unknown_not_failed_and_needs_confirmation_to_retry(): void
    {
        Mail::fake();
        $this->enable();
        $posts = 0;
        Http::fake([
            '*/Authentication' => Http::response(['accessToken' => 'tok'], 200),
            '*/Documents/WTOLeave' => function () use (&$posts) {
                if (++$posts === 1) {
                    throw new ConnectionException('cURL error 28: timed out');
                }

                return Http::response([['id' => '7', 'protocol' => 'ΕΥΣ7', 'submitDate' => '02/10/2026 10:15']], 200);
            },
        ]);

        $leave = $this->approvedLeave();

        $this->assertSame('unknown', $leave->ergani_status);
        $this->assertStringContainsString('Ελέγξτε στο ΕΡΓΑΝΗ', $leave->ergani_error);
        // A plain retry (e.g. the approve path again) is refused…
        $this->assertFalse(app(LeaveErganiSubmitter::class)->submit($leave->fresh()));
        $this->assertSame(1, $posts);

        // …only an explicit «I checked, it didn't land» may send again.
        $this->assertTrue(app(LeaveErganiSubmitter::class)->submit($leave->fresh(), null, confirmed: 'unknown'));
        $this->assertSame(2, $posts);
        $this->assertSame('ΕΥΣ7', $leave->fresh()->ergani_protocol);
    }

    public function test_login_failure_is_a_plain_failure(): void
    {
        Mail::fake();
        $this->enable();
        Http::fake(['*/Authentication' => fn () => throw new ConnectionException('cURL error 7: refused')]);

        $leave = $this->approvedLeave();

        $this->assertSame('failed', $leave->ergani_status, 'nothing was sent — safe to retry');
    }

    public function test_the_employees_private_note_never_reaches_ergani(): void
    {
        $e = Employee::create(['company_id' => $this->company->id, 'last_name' => 'Α', 'first_name' => 'Β', 'afm' => '123456783']);
        $leave = LeaveRequest::create(['company_id' => $this->company->id, 'employee_id' => $e->id, 'type' => LeaveType::Sick,
            'starts_on' => '2026-10-05', 'ends_on' => '2026-10-05', 'days' => 1, 'reason' => 'ιατρικές εξετάσεις για κάτι προσωπικό']);

        $payload = app(LeaveErganiSubmitter::class)->payload($leave);

        $this->assertSame('ekdosi #'.$leave->id, $payload['WTOS']['WTO'][0]['f_comments']);
        $this->assertStringNotContainsString('ιατρικές', json_encode($payload, JSON_UNESCAPED_UNICODE));
    }

    public function test_a_5xx_or_protocol_less_reply_is_unknown_but_a_400_is_a_failure(): void
    {
        Mail::fake();
        $this->enable();
        $this->fakeErgani(504, ['message' => 'Gateway Time-out']);
        $this->assertSame('unknown', $this->approvedLeave()->ergani_status);

        Cache::flush();
        Http::fake([
            '*/Authentication' => Http::response(['accessToken' => 'tok'], 200),
            '*/Documents/WTOLeave' => Http::response(['id' => '1'], 200), // 2xx without a protocol list
        ]);
        $e = Employee::create(['company_id' => $this->company->id, 'last_name' => 'Β', 'first_name' => 'Γ', 'afm' => '000000000']);
        $this->assertSame('unknown', $this->approvedLeave($e, '2026-11-02', '2026-11-02')->ergani_status);
    }

    public function test_approved_days_that_differ_from_the_dates_are_not_declared(): void
    {
        Mail::fake();
        $this->enable();
        $this->fakeErgani();
        $e = Employee::create(['company_id' => $this->company->id, 'last_name' => 'Α', 'first_name' => 'Β', 'afm' => '123456783']);
        $leave = LeaveRequest::create(['company_id' => $this->company->id, 'employee_id' => $e->id, 'type' => LeaveType::Annual,
            'starts_on' => '2026-10-02', 'ends_on' => '2026-10-05', 'days' => 2]);

        $done = app(LeaveWorkflow::class)->approve($leave, $this->makeUser(TenantRoleProvisioner::ROLE_COMPANY_ADMIN), null, 1);

        $this->assertSame('failed', $done->ergani_status);
        $this->assertStringContainsString('Τοπικές αργίες', $done->ergani_error);
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/Documents/WTOLeave'));
    }

    public function test_a_manually_recorded_protocol_is_withdrawn_if_the_leave_was_revoked(): void
    {
        Mail::fake();
        $this->enable();
        $this->fakeErgani(504, ['message' => 'Gateway Time-out']);
        $leave = $this->approvedLeave();
        $this->assertSame('unknown', $leave->ergani_status);
        app(LeaveWorkflow::class)->cancel($leave, $this->makeUser(TenantRoleProvisioner::ROLE_COMPANY_ADMIN));

        // The operator finds it in ΕΡΓΑΝΗ and records the protocol → it gets withdrawn.
        Cache::flush();
        $this->fakeErgani();
        app(LeaveErganiSubmitter::class)->recordProtocol($leave->fresh(), 'ΑΚ - ΟΡ1', CarbonImmutable::parse('2026-10-01 12:00', 'Europe/Athens'));

        $this->assertSame('cancelled', $leave->fresh()->ergani_status);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/Documents/CancelSubmittedDocument')
            && $r['Protocol'] === 'ΑΚ - ΟΡ1' && $r['SubmittedDate'] === '20261001');
    }

    public function test_a_double_withdrawal_sends_one_cancel(): void
    {
        Mail::fake();
        $this->enable();
        $this->fakeErgani();
        $leave = $this->approvedLeave();
        $leave->forceFill(['status' => LeaveStatus::Cancelled])->save();
        LeaveRequest::query()->whereKey($leave->id)->update(['ergani_status' => 'cancelling']); // first click in flight

        $this->assertFalse(app(LeaveErganiSubmitter::class)->cancel($leave->fresh()));
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/Documents/CancelSubmittedDocument'));
    }

    public function test_names_lose_every_accent_and_diaeresis(): void
    {
        $this->assertSame('ΠΡΟΙΣΤΑΜΕΝΗ', LeaveErganiSubmitter::erganiName('Προϊσταμένη', 50));
        $this->assertSame('ΔΙΑΛΥΤΙΚΑ Ι', LeaveErganiSubmitter::erganiName('διαλυτικά ΐ', 50));
    }

    public function test_record_protocol_never_overwrites_a_known_declaration_and_a_crashed_withdrawal_can_be_retried(): void
    {
        Mail::fake();
        $this->enable();
        $this->fakeErgani();
        $leave = $this->approvedLeave();

        $this->assertFalse(app(LeaveErganiSubmitter::class)->recordProtocol($leave->fresh(), 'ΑΛΛΟ', CarbonImmutable::now()));
        $this->assertSame('ΕΥΣ92', $leave->fresh()->ergani_protocol);

        $leave->forceFill(['status' => LeaveStatus::Cancelled])->save();
        LeaveRequest::query()->whereKey($leave->id)->update(['ergani_status' => 'cancelling', 'updated_at' => now()->subMinutes(LeaveErganiSubmitter::CLAIM_STALE_MINUTES + 1)]);

        $this->assertTrue(app(LeaveErganiSubmitter::class)->cancel($leave->fresh()));
        $this->assertSame('cancelled', $leave->fresh()->ergani_status);
    }

    public function test_confirmation_only_covers_the_state_the_modal_showed(): void
    {
        Mail::fake();
        $this->enable();
        $posts = 0;
        Http::fake([
            '*/Authentication' => Http::response(['accessToken' => 'tok'], 200),
            '*/Documents/WTOLeave' => function () use (&$posts) {
                return ++$posts === 1
                    ? Http::response(['message' => 'Gateway Time-out'], 504)
                    : Http::response([['id' => '9', 'protocol' => 'ΕΥΣ9', 'submitDate' => '02/10/2026 10:15']], 200);
            },
        ]);
        $leave = $this->approvedLeave();                       // → unknown
        $this->actAs($this->makeUser(TenantRoleProvisioner::ROLE_COMPANY_ADMIN));

        // The modal was opened while the state looked «failed» (no warning shown).
        Livewire::test(ViewLeaveRequest::class, ['record' => $leave->getRouteKey()])
            ->callAction('submitToErgani', data: ['seen' => 'plain']);
        $this->assertSame('unknown', $leave->fresh()->ergani_status);
        $this->assertSame(1, $posts, 'nothing re-sent');

        // Seen and confirmed → sent.
        Livewire::test(ViewLeaveRequest::class, ['record' => $leave->getRouteKey()])
            ->callAction('submitToErgani');
        $this->assertSame('submitted', $leave->fresh()->ergani_status);
    }

    public function test_record_protocol_refuses_while_an_attempt_is_live(): void
    {
        Mail::fake();
        $this->enable();
        $this->fakeErgani(504, ['message' => 'x']);
        $leave = $this->approvedLeave();
        LeaveRequest::query()->whereKey($leave->id)->update(['ergani_status' => 'submitting', 'updated_at' => now()]);

        $this->assertFalse(app(LeaveErganiSubmitter::class)->recordProtocol($leave->fresh(), 'ΑΚ - 1', CarbonImmutable::now()));
        $this->assertNull($leave->fresh()->ergani_protocol);
    }

    public function test_a_leave_the_accountant_was_asked_to_declare_needs_confirmation_and_he_is_told(): void
    {
        Mail::fake();
        $this->enable('production');
        $posts = 0;
        Http::fake([
            '*/Authentication' => Http::response(['accessToken' => 'tok'], 200),
            '*/Documents/WTOLeave' => function () use (&$posts) {
                return ++$posts === 1
                    ? Http::response(['message' => 'προσωρινό σφάλμα επικύρωσης'], 400)
                    : Http::response([['id' => '5', 'protocol' => 'ΕΥΣ5', 'submitDate' => '02/10/2026 10:15']], 200);
            },
        ]);
        $leave = $this->approvedLeave();                 // 400 → failed; accountant emailed «please declare»
        $this->assertSame('failed', $leave->ergani_status);
        $this->assertNotNull($leave->accountant_notified_at);

        // A plain retry is refused — he may already have declared it.
        $this->assertFalse(app(LeaveErganiSubmitter::class)->submit($leave->fresh()));
        $this->assertSame(1, $posts);

        // The UI asks for confirmation, then tells him ekdosi declared it.
        $this->actAs($this->makeUser(TenantRoleProvisioner::ROLE_COMPANY_ADMIN));
        Livewire::test(ViewLeaveRequest::class, ['record' => $leave->getRouteKey()])
            ->callAction('submitToErgani');
        $this->assertSame('submitted', $leave->fresh()->ergani_status);
        Mail::assertSent(LeaveAccountantMail::class, fn (LeaveAccountantMail $m) => $m->event === 'declared'
            && str_contains($m->intro(), 'ανακαλέστε τη ΜΙΑ'));
    }

    public function test_the_confirmation_is_bound_to_the_environment_the_modal_showed(): void
    {
        Mail::fake();
        $this->enable('production');
        $this->fakeErgani(400, ['message' => 'x']);
        $leave = $this->approvedLeave();
        Cache::flush();
        $this->actAs($this->makeUser(TenantRoleProvisioner::ROLE_COMPANY_ADMIN));

        Livewire::test(ViewLeaveRequest::class, ['record' => $leave->getRouteKey()])
            ->callAction('submitToErgani', data: ['seen' => 'accountant', 'seen_mode' => 'trial']);

        $this->assertSame('failed', $leave->fresh()->ergani_status, 'the modal said trial — nothing filed in production');
        $this->assertCount(1, Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/Documents/WTOLeave')));
    }

    public function test_an_ambiguous_4xx_is_unknown(): void
    {
        Mail::fake();
        $this->enable();
        $this->fakeErgani(408, ['message' => 'Request Timeout']);

        $this->assertSame('unknown', $this->approvedLeave()->ergani_status);
    }

    public function test_resuming_a_crashed_withdrawal_that_had_landed_ends_cancelled(): void
    {
        Mail::fake();
        $this->enable();
        Http::fake([
            '*/Authentication' => Http::response(['accessToken' => 'tok'], 200),
            '*/Documents/WTOLeave' => Http::response([['id' => '1', 'protocol' => 'ΕΥΣ1', 'submitDate' => '02/10/2026 10:15']], 200),
            '*/Documents/CancelSubmittedDocument' => Http::response(['message' => 'No objects found'], 400),
        ]);
        $leave = $this->approvedLeave();
        $leave->forceFill(['status' => LeaveStatus::Cancelled])->save();
        LeaveRequest::query()->whereKey($leave->id)->update(['ergani_status' => 'cancelling', 'updated_at' => now()->subMinutes(LeaveErganiSubmitter::CLAIM_STALE_MINUTES + 1)]);

        $this->assertTrue(app(LeaveErganiSubmitter::class)->cancel($leave->fresh()));
        $this->assertSame('cancelled', $leave->fresh()->ergani_status);

        // …whereas a first-time «No objects found» (e.g. wrong date) stays a failure.
        LeaveRequest::query()->whereKey($leave->id)->update(['ergani_status' => 'submitted']);
        $this->assertFalse(app(LeaveErganiSubmitter::class)->cancel($leave->fresh()));
        $this->assertSame('cancel_failed', $leave->fresh()->ergani_status);
    }

    public function test_every_audit_row_carries_the_leaves_pinned_environment(): void
    {
        Mail::fake();
        $this->enable('production');
        $this->fakeErgani(504, ['message' => 'x']);
        $leave = $this->approvedLeave();                 // unknown, pinned to production
        $this->company->forceFill(['ergani_mode' => 'trial'])->save();

        app(LeaveErganiSubmitter::class)->recordProtocol($leave->fresh(), 'ΑΚ - 2', CarbonImmutable::parse('2026-10-02 12:00', 'Europe/Athens'));

        $this->assertSame(['production', 'production'], ErganiSubmission::query()->orderBy('id')->pluck('environment')->all());
    }

    public function test_a_confirmation_covers_only_the_state_it_was_given_for(): void
    {
        Mail::fake();
        $this->enable();
        $this->fakeErgani(504, ['message' => 'x']);
        $leave = $this->approvedLeave();                  // unknown (and the accountant was emailed)

        // «The accountant was asked» does not cover an UNKNOWN outcome.
        $this->assertFalse(app(LeaveErganiSubmitter::class)->submit($leave->fresh(), null, confirmed: 'accountant'));
        $this->assertSame('unknown', $leave->fresh()->ergani_status);
        // Nor does a made-up kind.
        $this->assertFalse(app(LeaveErganiSubmitter::class)->submit($leave->fresh(), null, confirmed: 'anything'));
    }

    public function test_declared_email_never_clears_an_owed_revocation(): void
    {
        Mail::fake();
        $this->enable();
        $this->fakeErgani();
        $leave = $this->approvedLeave();
        LeaveRequest::query()->whereKey($leave->id)->update(['accountant_owed_event' => 'cancelled']);

        app(LeaveWorkflow::class)->notifyAccountant($leave->fresh(), 'declared');

        $this->assertSame('cancelled', $leave->fresh()->accountant_owed_event);
    }
}
