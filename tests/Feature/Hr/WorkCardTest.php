<?php

namespace Tests\Feature\Hr;

use App\Filament\Pages\WorkCard;
use App\Filament\Pages\WorkCardKiosk;
use App\Filament\Resources\Employees\Pages\EditEmployee;
use App\Filament\Resources\Employees\Pages\ListEmployees;
use App\Filament\Resources\Employees\Widgets\StaffSetupChecklist;
use App\Filament\Resources\WorkCardEvents\WorkCardEventResource;
use App\Models\Company;
use App\Models\Employee;
use App\Models\ErganiSubmission;
use App\Models\User;
use App\Models\WorkCardEvent;
use App\Models\WorkCardKioskDevice;
use App\Services\Ergani\ErganiClient;
use App\Services\Ergani\WorkCardRefused;
use App\Services\Ergani\WorkCardService;
use App\Services\TenantRoleProvisioner;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use RuntimeException;
use Spatie\Permission\Models\Permission;

class WorkCardTest extends HrTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach (['View:WorkCard', 'View:WorkCardKiosk', 'ViewAny:WorkCardEvent', 'View:WorkCardEvent', 'Create:WorkCardEvent', 'Update:WorkCardEvent'] as $p) {
            Permission::findOrCreate($p, 'web');
        }
        app(TenantRoleProvisioner::class)->ensureStandardRoles($this->company);
        $this->company->forceFill(['afm' => '800561849'])->save();
    }

    private function enable(string $mode = 'trial'): void
    {
        $this->company->forceFill(['ergani_submit_cards' => true, 'ergani_mode' => $mode, 'ergani_username' => 'U', 'ergani_password' => 'P'])->save();
        Cache::flush();
    }

    private function fakeErgani(int $status = 200, array $body = [['id' => '5817437', 'protocol' => 'ΑΚ - ΚΑΡ1', 'submitDate' => '25/09/2026 21:16']]): void
    {
        Http::fake([
            '*/Authentication' => Http::response(['accessToken' => 'tok'], 200),
            '*/Documents/WRKCardSE' => Http::response($body, $status),
        ]);
    }

    private function cardEmployee(?User $user = null): Employee
    {
        return Employee::create(['company_id' => $this->company->id, 'user_id' => $user?->id, 'last_name' => 'Κάρτας',
            'first_name' => 'Δοκιμή', 'afm' => '234567897', 'has_work_card' => true]);
    }

    public function test_in_then_out_declared_with_the_verified_payload(): void
    {
        $this->enable();
        $this->fakeErgani();
        $e = $this->cardEmployee();
        $svc = app(WorkCardService::class);

        $in = $svc->punch($e, 'self', null, null, CarbonImmutable::now()->subMinutes(5));
        $out = $svc->punch($e, 'self');

        $this->assertSame([WorkCardEvent::IN, WorkCardEvent::OUT], [$in->type, $out->type]);
        $this->assertSame('submitted', $out->ergani_status);
        $this->assertSame('ΑΚ - ΚΑΡ1', $out->ergani_protocol);
        Http::assertSent(function (Request $r): bool {
            if (! str_ends_with($r->url(), '/Documents/WRKCardSE')) {
                return false;
            }
            $card = $r['Cards']['Card'][0];
            $d = $card['Details']['CardDetails'][0];

            return str_starts_with($r->url(), ErganiClient::TRIAL_URL)
                && $card['f_afm_ergodoti'] === '800561849' && $card['f_aa'] === '0'
                && $d['f_afm'] === '234567897' && $d['f_eponymo'] === 'ΚΑΡΤΑΣ' && $d['f_onoma'] === 'ΔΟΚΙΜΗ'
                && in_array($d['f_type'], ['0', '1'], true)
                && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}\+0[23]:00$/', $d['f_date'])
                && $d['f_aitiologia'] === null;
        });
        $this->assertSame(2, ErganiSubmission::query()->where('document', 'WRKCardSE')->count());
    }

    public function test_double_punch_is_refused_and_nothing_extra_is_declared(): void
    {
        $this->enable();
        $this->fakeErgani();
        $e = $this->cardEmployee();
        app(WorkCardService::class)->punch($e, 'self');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('διπλό χτύπημα');
        app(WorkCardService::class)->punch($e, 'self');
    }

    public function test_an_out_after_midnight_keeps_the_in_day(): void
    {
        $e = $this->cardEmployee();
        $svc = app(WorkCardService::class);
        $in = $svc->punch($e, 'admin', null, 'in', CarbonImmutable::parse('2026-09-24 22:00', 'Europe/Athens'), null, '002');
        $out = $svc->punch($e, 'admin', null, null, CarbonImmutable::parse('2026-09-25 06:00', 'Europe/Athens'), null, '002');

        $this->assertSame('out', $out->type);
        $this->assertSame('2026-09-24', $out->reference_date->toDateString());
    }

    public function test_late_movement_needs_a_reason_and_carries_it(): void
    {
        $this->enable();
        $this->fakeErgani();
        $e = $this->cardEmployee();
        $late = app(WorkCardService::class)->punch($e, 'admin', null, 'in', CarbonImmutable::now()->subHour());
        $this->assertSame('failed', $late->ergani_status);
        $this->assertStringContainsString('αιτιολογία', $late->ergani_error);
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/Documents/WRKCardSE'));

        $this->assertTrue(app(WorkCardService::class)->submit($late->fresh(), null, '003'));
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/Documents/WRKCardSE')
            && $r['Cards']['Card'][0]['Details']['CardDetails'][0]['f_aitiologia'] === '003');
        $this->assertSame('003', $late->fresh()->late_reason);
    }

    public function test_nothing_is_declared_without_opt_in_or_without_a_card_flag(): void
    {
        Http::fake();
        $e = $this->cardEmployee();
        $event = app(WorkCardService::class)->punch($e, 'self');
        $this->assertNull($event->ergani_status, 'tenant not opted in → recorded only');

        $this->enable();
        $noCard = Employee::create(['company_id' => $this->company->id, 'last_name' => 'Χωρίς', 'first_name' => 'Κάρτα', 'afm' => '123456783']);
        $this->assertNull(app(WorkCardService::class)->punch($noCard, 'self')->ergani_status);
        Http::assertNothingSent();
    }

    public function test_timeout_is_unknown_and_not_retried_without_confirmation(): void
    {
        $this->enable();
        $posts = 0;
        Http::fake([
            '*/Authentication' => Http::response(['accessToken' => 'tok'], 200),
            '*/Documents/WRKCardSE' => function () use (&$posts) {
                $posts++;
                throw new ConnectionException('cURL error 28');
            },
        ]);
        $event = app(WorkCardService::class)->punch($this->cardEmployee(), 'self');

        $this->assertSame('unknown', $event->ergani_status);
        $this->assertFalse(app(WorkCardService::class)->submit($event->fresh()));
        $this->assertSame(1, $posts);
    }

    public function test_kiosk_token_rotates_and_expires(): void
    {
        $svc = app(WorkCardService::class);
        $token = $svc->kioskToken($this->company);
        $this->assertTrue($svc->kioskTokenValid($this->company, $token));
        $this->assertFalse($svc->kioskTokenValid($this->company, 'x'.$token));

        $old = $svc->kioskToken($this->company, intdiv(time(), WorkCardService::KIOSK_WINDOW) - 10);
        $this->assertFalse($svc->kioskTokenValid($this->company, $old), 'an old photo of the QR is useless');

        $other = Company::create(['name' => 'Other', 'slug' => 'o-'.uniqid(), 'country_code' => 'GR']);
        $this->assertFalse($svc->kioskTokenValid($other, $token), 'a token is bound to its company');
    }

    public function test_kiosk_required_refuses_the_plain_button(): void
    {
        $this->company->forceFill(['ergani_card_requires_kiosk' => true])->save();
        $e = $this->cardEmployee();

        $this->assertSame('kiosk', app(WorkCardService::class)->punch($e, 'kiosk')->source);
        $this->expectException(RuntimeException::class);
        app(WorkCardService::class)->punch($e, 'self');
    }

    public function test_staff_punch_from_their_page_via_the_office_qr(): void
    {
        $this->enable();
        $this->fakeErgani();
        $user = $this->actAs($this->makeUser(TenantRoleProvisioner::ROLE_ERGANI));
        $e = $this->cardEmployee($user);
        $token = app(WorkCardService::class)->kioskToken($this->company);

        $this->assertTrue(WorkCard::canAccess());
        $this->assertFalse(WorkCardKiosk::canAccess(), 'the kiosk is admin-only');
        $this->assertFalse(WorkCardEventResource::canAccess(), 'the list is admin-only');
        $this->get(WorkCard::getUrl(tenant: $this->company))->assertOk();

        Livewire::test(WorkCard::class, ['kiosk' => $token])->callAction('punch');

        $event = WorkCardEvent::query()->sole();
        $this->assertSame([$e->id, 'in', 'kiosk', 'submitted'], [$event->employee_id, $event->type, $event->source, $event->ergani_status]);
        foreach (['employee', 'todayEvents', 'viaKiosk', 'tenant'] as $m) {
            $this->assertFalse((new \ReflectionMethod(WorkCard::class, $m))->isPublic(), "{$m}() must not be a public Livewire method");
        }
    }

    public function test_admin_sees_kiosk_and_list(): void
    {
        $this->actAs($this->makeUser(TenantRoleProvisioner::ROLE_COMPANY_ADMIN));
        $this->assertTrue(WorkCardKiosk::canAccess());
        $this->assertTrue(WorkCardEventResource::canAccess());
        $this->get(WorkCardKiosk::getUrl(tenant: $this->company))->assertOk()->assertSee('data:image/png;base64', false);
    }

    public function test_a_failed_staff_punch_bells_the_admins(): void
    {
        $this->enable();
        $this->fakeErgani(400, ['message' => 'Χωρίς Ένδειξη Κάρτας Εργασίας.']);
        $admin = $this->makeUser(TenantRoleProvisioner::ROLE_COMPANY_ADMIN);
        $staff = $this->makeUser(TenantRoleProvisioner::ROLE_ERGANI);

        app(WorkCardService::class)->punch($this->cardEmployee($staff), 'self', $staff->id);

        $this->assertSame(1, $admin->notifications()->count());
        $this->assertSame(0, $staff->notifications()->count());
    }

    public function test_devices_are_individually_activated_listed_and_revoked(): void
    {
        [$a, $tokenA] = WorkCardKioskDevice::activate($this->company, 'Ρεσεψιόν', null);
        [$b, $tokenB] = WorkCardKioskDevice::activate($this->company, 'Αποθήκη', null);

        $this->withCookie('ergani_kiosk', $tokenA)->get('/card-kiosk')->assertSee('data:image/png;base64', false);
        $this->assertNotNull($a->fresh()->last_seen_at, 'last seen is recorded');
        $this->assertNotSame($tokenA, $a->fresh()->getRawOriginal('token_hash'), 'only the hash is stored');

        $a->forceFill(['revoked_at' => now()])->save();   // lost tablet
        $this->withCookie('ergani_kiosk', $tokenA)->get('/card-kiosk')->assertDontSee('data:image/png;base64', false);
        $this->withCookie('ergani_kiosk', $tokenB)->get('/card-kiosk')->assertSee('data:image/png;base64', false);

        // Admin page lists the live devices and revokes one by one — own tenant only.
        $this->actAs($this->makeUser(TenantRoleProvisioner::ROLE_COMPANY_ADMIN));
        $other = Company::create(['name' => 'O', 'slug' => 'o-'.uniqid(), 'country_code' => 'GR', 'ergani_enabled' => true]);
        [$foreign] = WorkCardKioskDevice::activate($other, 'Ξένο', null);
        Livewire::test(WorkCardKiosk::class)
            ->assertSee('Αποθήκη')->assertDontSee('Ρεσεψιόν')->assertDontSee('Ξένο')
            ->callAction('revokeDevice', arguments: ['device' => $b->id])
            ->callAction('revokeDevice', arguments: ['device' => $foreign->id]);
        $this->assertNotNull($b->fresh()->revoked_at);
        $this->assertNull($foreign->fresh()->revoked_at, 'another tenant\'s device is untouchable');
    }

    public function test_the_tablet_shows_nothing_on_an_unknown_device(): void
    {
        $this->get('/card-kiosk')->assertOk()->assertDontSee('data:image/png;base64', false)->assertSee('δεν είναι ενεργοποιημένη');
        $this->withCookie('ergani_kiosk', str_repeat('b', 48))->get('/card-kiosk')->assertDontSee('data:image/png;base64', false);
    }

    public function test_backdated_and_out_of_sequence_punches_are_refused(): void
    {
        $e = $this->cardEmployee();
        $svc = app(WorkCardService::class);
        $svc->punch($e, 'admin', null, 'in', CarbonImmutable::now()->subHours(8), null, '002');
        $svc->punch($e, 'admin', null, 'out', CarbonImmutable::now()->subMinutes(30), null, '002');

        foreach ([
            fn () => $svc->punch($e, 'admin', null, 'in', CarbonImmutable::now()->subHours(9), null, '002'),      // before later events
            fn () => $svc->punch($e, 'admin', null, 'out', CarbonImmutable::now()->subMinutes(5)),               // out after out
            fn () => $svc->punch($e, 'admin', null, 'in', CarbonImmutable::now()->subMinutes(30)->addSeconds(20), null, '002'), // within 60s
            fn () => $svc->punch($e, 'admin', null, 'in', CarbonImmutable::now()->addMinutes(5)),                 // future
        ] as $i => $attempt) {
            try {
                $attempt();
                $this->fail("attempt {$i} must be refused");
            } catch (WorkCardRefused) {
            }
        }
        $this->assertSame(2, WorkCardEvent::query()->count());
    }

    public function test_a_forgotten_out_does_not_make_next_mornings_tap_an_exit(): void
    {
        $e = $this->cardEmployee();
        app(WorkCardService::class)->punch($e, 'admin', null, 'in', CarbonImmutable::now()->subHours(20), null, '002');

        $this->assertSame(WorkCardEvent::IN, app(WorkCardService::class)->nextType($e));
    }

    public function test_a_utc_time_is_stored_and_declared_in_greek_time(): void
    {
        $e = $this->cardEmployee();
        $utc = CarbonImmutable::now('UTC')->subMinutes(2)->startOfMinute();
        $event = app(WorkCardService::class)->punch($e, 'admin', null, 'in', $utc);

        $this->assertTrue($event->occurred_at->equalTo($utc));
        $this->assertStringEndsWith($utc->setTimezone('Europe/Athens')->format('P'),
            app(WorkCardService::class)->payload($event)['Cards']['Card'][0]['Details']['CardDetails'][0]['f_date']);
    }

    private function activatedTablet(): void
    {
        [, $token] = WorkCardKioskDevice::activate($this->company, 'Tablet', null);
        $this->withCookie('ergani_kiosk', $token)->withCredentials();   // JSON calls send cookies only withCredentials
    }

    public function test_tablet_pin_clock_punches_and_shows_who_is_in(): void
    {
        $this->enable();
        $this->fakeErgani();
        $e = $this->cardEmployee();
        $e->forceFill(['card_pin_hash' => Hash::make('4321')])->save();
        $other = Employee::create(['company_id' => $this->company->id, 'last_name' => 'Χωρίς', 'first_name' => 'Pin', 'afm' => '123456783']);
        $this->activatedTablet();

        $this->get('/card-kiosk')->assertOk()->assertSee('Κάρτας Δοκιμή')->assertSee('Εκτός γραφείου')->assertSee('(χωρίς PIN', false);

        $this->withoutMiddleware(PreventRequestForgery::class)
            ->postJson('/card-kiosk/punch', ['employee' => $e->id, 'pin' => '4321', 'seen' => 'in'])
            ->assertOk()->assertJson(['ok' => true]);

        $event = WorkCardEvent::query()->sole();
        $this->assertSame(['in', 'kiosk', 'submitted'], [$event->type, $event->source, $event->ergani_status]);
        $this->get('/card-kiosk')->assertSee('Μέσα από '.$event->occurred_at->format('H:i'));
        $this->assertArrayNotHasKey('card_pin_hash', $e->fresh()->toArray(), 'the PIN hash never serializes');
    }

    public function test_wrong_pins_lock_the_employee_and_other_tenants_or_devices_get_nothing(): void
    {
        $e = $this->cardEmployee();
        $e->forceFill(['card_pin_hash' => Hash::make('4321')])->save();
        $this->activatedTablet();
        $post = fn (array $d) => $this->withoutMiddleware(PreventRequestForgery::class)->postJson('/card-kiosk/punch', $d);

        for ($i = 1; $i <= WorkCardService::PIN_MAX_FAILURES; $i++) {
            $post(['employee' => $e->id, 'pin' => '0000'])->assertStatus(422);
        }
        $post(['employee' => $e->id, 'pin' => '4321'])->assertStatus(422)->assertJsonFragment(['ok' => false]);
        $this->assertSame(0, WorkCardEvent::query()->count(), 'locked out even with the right PIN');
        $this->assertTrue($e->fresh()->card_pin_locked_until->isFuture());

        // Another tenant's employee id via this tablet → refused.
        $otherCo = Company::create(['name' => 'X', 'slug' => 'x-'.uniqid(), 'country_code' => 'GR', 'ergani_enabled' => true]);
        $foreign = Employee::create(['company_id' => $otherCo->id, 'last_name' => 'Ξ', 'first_name' => 'Ξ', 'card_pin_hash' => Hash::make('1111')]);
        $post(['employee' => $foreign->id, 'pin' => '1111'])->assertStatus(422);

        // A non-activated device can't punch at all.
        $this->withCookie('ergani_kiosk', 'nope');
        $post(['employee' => $e->id, 'pin' => '4321'])->assertStatus(403);
        $this->assertSame(0, WorkCardEvent::query()->count());
    }

    public function test_the_public_url_reveals_nothing_without_an_activated_device(): void
    {
        $e = $this->cardEmployee();
        $e->forceFill(['card_pin_hash' => Hash::make('4321')])->save();

        $this->get('/card-kiosk')->assertOk()
            ->assertDontSee('Κάρτας Δοκιμή')->assertDontSee('Μέσα τώρα')->assertDontSee('data:image/png;base64', false);
        $this->withoutMiddleware(PreventRequestForgery::class)
            ->postJson('/card-kiosk/punch', ['employee' => $e->id, 'pin' => '4321'])->assertStatus(403);
    }

    public function test_pin_spraying_pauses_only_that_tablet_and_lockouts_escalate(): void
    {
        $svc = app(WorkCardService::class);
        $admin = $this->makeUser(TenantRoleProvisioner::ROLE_COMPANY_ADMIN);
        [$lobby] = WorkCardKioskDevice::activate($this->company, 'Lobby', null);
        [$store] = WorkCardKioskDevice::activate($this->company, 'Αποθήκη', null);
        $employees = collect(range(1, 5))->map(fn ($i) => Employee::create(['company_id' => $this->company->id,
            'last_name' => 'E'.$i, 'first_name' => 'X', 'card_pin_hash' => Hash::make('98'.$i.'7')]));

        $paused = 0;
        for ($i = 0; $i < WorkCardService::PIN_DEVICE_FAILURES + 3; $i++) {
            try {
                $svc->punchWithPin($lobby, $employees[$i % 5]->id, '1234');
            } catch (WorkCardRefused $e) {
                $paused += str_contains($e->getMessage(), 'tablet') ? 1 : 0;
            }
        }
        $this->assertGreaterThan(0, $paused, 'the sprayed tablet pauses');
        $this->assertSame(1, $admin->notifications()->count(), 'admins belled once');

        // The OTHER tablet keeps working.
        $event = $svc->punchWithPin($store, $employees[1]->id, '9827');
        $this->assertSame('kiosk', $event->source);

        // Lockouts escalate instead of resetting.
        $e = $employees[0];
        $e->forceFill(['card_pin_failures' => 5, 'card_pin_locked_until' => null])->save();
        for ($i = 0; $i < WorkCardService::PIN_MAX_FAILURES; $i++) {
            try {
                $svc->punchWithPin($store, $e->id, '0000');
            } catch (WorkCardRefused) {
            }
        }
        $this->assertSame(10, $e->fresh()->card_pin_failures);
        $this->assertTrue($e->fresh()->card_pin_locked_until->gt(now()->addMinutes(25)), 'second lockout is ~30 minutes');
    }

    public function test_the_tablet_view_renews_its_device_cookie(): void
    {
        $this->activatedTablet();
        $this->get('/card-kiosk')->assertCookie('ergani_kiosk');
        $this->assertArrayNotHasKey('ergani_password', $this->company->fresh()->toArray());
    }

    public function test_activating_a_tablet_logs_the_admin_out(): void
    {
        $this->actAs($this->makeUser(TenantRoleProvisioner::ROLE_COMPANY_ADMIN));

        Livewire::test(WorkCardKiosk::class)
            ->callAction('activateDevice', data: ['name' => 'Ρεσεψιόν'])
            ->assertRedirect(route('ergani.card-kiosk'));

        $this->assertGuest();
        $this->assertSame(1, WorkCardKioskDevice::query()->withoutGlobalScopes()->count());
    }

    public function test_an_admin_can_unlock_a_locked_pin_and_a_new_pin_clears_the_lock(): void
    {
        $this->actAs($this->makeUser(TenantRoleProvisioner::ROLE_COMPANY_ADMIN));
        $e = $this->cardEmployee();
        $e->forceFill(['card_pin_hash' => Hash::make('1111'), 'card_pin_failures' => 5, 'card_pin_locked_until' => now()->addMinutes(15)])->save();

        Livewire::test(ListEmployees::class)->callTableAction('unlockPin', $e);
        $this->assertNull($e->fresh()->card_pin_locked_until);
        $this->assertSame(0, $e->fresh()->card_pin_failures);

        $e->forceFill(['card_pin_failures' => 5, 'card_pin_locked_until' => now()->addMinutes(15)])->save();
        Livewire::test(EditEmployee::class, ['record' => $e->getRouteKey()])
            ->fillForm(['card_pin_hash' => '2468'])->call('save')->assertHasNoFormErrors();
        $this->assertNull($e->fresh()->card_pin_locked_until, 'a new PIN clears the lockout');
        $this->assertTrue(Hash::check('2468', $e->fresh()->card_pin_hash));
    }

    public function test_punch_page_shows_in_out_state_expired_qr_and_is_hidden_from_the_menu_without_an_employee(): void
    {
        $user = $this->actAs($this->makeUser(TenantRoleProvisioner::ROLE_ERGANI));
        $this->assertFalse(WorkCard::shouldRegisterNavigation(), 'no employee record → not in the menu');

        $e = $this->cardEmployee($user);
        $this->assertTrue(WorkCard::shouldRegisterNavigation());
        Livewire::test(WorkCard::class)->assertSee('Είστε ΕΚΤΟΣ');
        Livewire::test(WorkCard::class, ['kiosk' => 'stale-token'])->assertSee('Το QR που σκανάρατε έληξε');

        app(WorkCardService::class)->punch($e, 'self');
        Livewire::test(WorkCard::class)->assertSee('Είστε ΜΕΣΑ από')->assertDontSee('έληξε');
        $this->assertFalse((new \ReflectionMethod(StaffSetupChecklist::class, 'steps'))->isPublic());
    }

    public function test_setup_checklist_lists_missing_steps_and_collapses_when_done(): void
    {
        $this->actAs($this->makeUser(TenantRoleProvisioner::ROLE_COMPANY_ADMIN));
        $this->enable();

        Livewire::test(StaffSetupChecklist::class)
            ->assertSee('Ξεκίνημα Προσωπικού')->assertSee('Email λογιστή')->assertSee('Tablet γραφείου');

        $u = $this->makeUser(TenantRoleProvisioner::ROLE_ERGANI);
        $e = $this->cardEmployee($u);
        $e->forceFill(['card_pin_hash' => Hash::make('2468')])->save();
        $this->company->forceFill(['leave_notify_email' => 'acc@example.test'])->save();
        WorkCardKioskDevice::activate($this->company, 'Ρεσεψιόν', null);

        Livewire::test(StaffSetupChecklist::class)->assertSee('όλα έτοιμα')->assertSee('Ξεκίνημα Προσωπικού');
    }
}
