<?php

namespace Tests\Feature\Hr;

use App\Filament\Widgets\ErganiStatusWidget;
use App\Models\Employee;
use App\Models\User;
use App\Services\Ergani\ErganiClient;
use App\Services\Ergani\ErganiRosterWatch;
use App\Services\TenantRoleProvisioner;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/** «Φύλακας προσωπικού»: ΕΡΓΑΝΗ roster (EX_BASE_05) vs Εργαζόμενοι — stored, bell only on a change, never edits. */
class ErganiRosterWatchTest extends HrTestCase
{
    /** @var list<array<string, mixed>> */
    private array $roster = [];

    /** @var list<string> */
    private array $urls = [];

    private int $rosterStatus = 200;

    private string $sector = '0';

    protected function setUp(): void
    {
        parent::setUp();
        $this->company->forceFill(['afm' => '800561849', 'ergani_mode' => 'trial', 'ergani_username' => 'U', 'ergani_password' => 'P'])->save();
        Cache::flush();
        Http::fake(function (Request $r) {
            $this->urls[] = $r->url();

            return match (true) {
                str_ends_with($r->url(), '/Authentication') => Http::response(['accessToken' => 'tok']),
                ($r->data()['ServiceCode'] ?? null) === 'EX_BASE_05' => $this->rosterStatus === 200
                    ? Http::response(['EX_BASE_05' => ['Cur' => $this->roster]])
                    : Http::response(['message' => 'boom'], $this->rosterStatus),
                default => Http::response(['EX_BASE_01' => ['Ergodotis' => ['Afm' => '800561849', 'IsInCardSector' => $this->sector]]]),
            };
        });
    }

    private function row(string $afm, string $last, string $first): array
    {
        return ['afm' => $afm, 'Eponimo' => $last, 'Onoma' => $first, 'PararthmaAa' => 0, 'DateFrom' => '2021-03-01T00:00:00+02:00', 'Amka' => 'x'];
    }

    private function emp(string $afm, string $last, bool $active = true): Employee
    {
        return Employee::create(['company_id' => $this->company->id, 'afm' => $afm ?: null, 'last_name' => $last, 'first_name' => 'Χ', 'is_active' => $active]);
    }

    public function test_diff_finds_new_inactive_missing_and_no_afm_and_changes_nothing(): void
    {
        $this->roster = [$this->row('111111111', 'ΝΕΟΣ', 'ΠΡΩΤΟΣ'), $this->row('222222222', 'ΙΔΙΟΣ', 'Χ'), $this->row('333333333', 'ΓΥΡΙΣΕ', 'Χ')];
        $same = $this->emp('222222222', 'Ίδιος');
        $back = $this->emp('333333333', 'Γύρισε', false);
        $gone = $this->emp('444444444', 'Έφυγε');
        $noAfm = $this->emp('', 'Χωρίςαφμ');

        $diff = app(ErganiRosterWatch::class)->diff($this->company);

        $this->assertSame([['afm' => '111111111', 'name' => 'Νεος Πρωτος']], $diff['new']);
        $this->assertSame(['333333333'], array_column($diff['inactive'], 'afm'));
        $this->assertSame([$gone->id], array_column($diff['missing'], 'id'));
        $this->assertSame([$noAfm->id], array_column($diff['no_afm'], 'id'));
        $this->assertSame(4, Employee::count(), 'nothing created');
        $this->assertFalse($back->fresh()->is_active, 'nothing re-activated');
        $this->assertTrue($gone->fresh()->is_active, 'nothing deactivated');
        foreach ($this->urls as $u) {
            $this->assertStringStartsWith(ErganiClient::PRODUCTION_URL, $u);
        }
        $this->assertStringNotContainsString('Amka', json_encode($diff));
    }

    public function test_weekly_watch_stores_the_diff_and_bells_only_when_it_changes(): void
    {
        $admin = $this->makeUser(TenantRoleProvisioner::ROLE_COMPANY_ADMIN);
        $this->emp('222222222', 'Ίδιος');
        $this->roster = [$this->row('222222222', 'ΙΔΙΟΣ', 'Χ')];

        $this->artisan('ergani:watch')->assertSuccessful();
        $this->assertSame(0, ErganiRosterWatch::count($this->company->fresh()->ergani_roster_diff));
        $this->assertSame(0, User::find($admin->id)->notifications()->count(), 'no difference → no bell');

        $this->roster[] = $this->row('111111111', 'ΝΕΟΣ', 'ΠΡΩΤΟΣ');
        $this->artisan('ergani:watch')->assertSuccessful();
        $this->artisan('ergani:watch')->assertSuccessful();
        $this->assertSame(1, User::find($admin->id)->notifications()->count(), 'one bell for the new difference, not one per week');
        $this->assertNotNull($this->company->fresh()->ergani_roster_checked_at);

        $this->actAs($admin, $this->company->fresh());
        Livewire::test(ErganiStatusWidget::class)->assertSee('νέος/οι στο ΕΡΓΑΝΗ που δεν υπάρχουν εδώ: Νεος Πρωτος');
    }

    public function test_a_roster_failure_does_not_skip_the_card_check(): void
    {
        $this->rosterStatus = 500;
        $this->sector = '1';

        $this->artisan('ergani:watch')->assertFailed();
        $this->assertTrue($this->company->fresh()->ergani_card_sector, 'card check still ran');
        $this->assertNull($this->company->fresh()->ergani_roster_checked_at, 'failed roster read stores nothing');
    }
}
