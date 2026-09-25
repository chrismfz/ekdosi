<?php

namespace Tests\Feature\Hr;

use App\Filament\Pages\LeaveCalendar;
use App\Filament\Resources\CompanyHolidays\CompanyHolidayResource;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Resources\LeaveRequests\LeaveRequestResource;
use App\Services\Ergani\ErganiClient;
use App\Services\TenantRoleProvisioner;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ErganiModuleTest extends HrTestCase
{
    public function test_switching_the_pillar_off_hides_every_hr_screen(): void
    {
        $this->actAs($this->makeUser(TenantRoleProvisioner::ROLE_COMPANY_ADMIN));
        $this->assertTrue(LeaveRequestResource::canAccess());

        $this->company->forceFill(['ergani_enabled' => false])->save();

        foreach ([LeaveRequestResource::class, EmployeeResource::class, CompanyHolidayResource::class, LeaveCalendar::class] as $screen) {
            $this->assertFalse($screen::canAccess(), "{$screen} hidden when ΕΡΓΑΝΗ is off");
        }
    }

    public function test_password_is_encrypted_at_rest(): void
    {
        config(['ekdosi.secrets.encrypt_at_rest' => true]);
        $this->company->forceFill(['ergani_username' => 'EFKA1', 'ergani_password' => 's3cret'])->save();

        $raw = \DB::table('companies')->where('id', $this->company->id)->value('ergani_password');
        $this->assertNotSame('s3cret', $raw);
        $this->assertSame('s3cret', $this->company->fresh()->ergani_password);
    }

    public function test_connection_test_hits_the_selected_environment(): void
    {
        Http::fake([
            '*/Authentication' => Http::response(['accessToken' => 'tok', 'refreshToken' => 'r'], 200),
            '*/WebServices/ExecuteService' => Http::response(['EX_BASE_01' => ['Ergodotis' => [
                'Afm' => '800561849', 'Eponimia' => 'MYIP', 'IsInCardSector' => '0',
            ]]], 200),
        ]);
        $this->company->forceFill(['ergani_username' => 'EFKA1', 'ergani_password' => 'pw', 'ergani_mode' => 'trial'])->save();

        $info = (new ErganiClient($this->company->fresh()))->employerInfo();

        $this->assertSame(['afm' => '800561849', 'name' => 'MYIP', 'in_card_sector' => false, 'environment' => 'Δοκιμαστικό'], $info);
        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), ErganiClient::TRIAL_URL.'/Authentication')
            && $r['Usertype'] === '01' && $r['Username'] === 'EFKA1');
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'ExecuteService') && $r->hasHeader('Authorization', 'Bearer tok'));

        $this->company->forceFill(['ergani_mode' => 'production'])->save();
        $this->assertSame(ErganiClient::PRODUCTION_URL, (new ErganiClient($this->company->fresh()))->baseUrl());
    }

    public function test_rejected_credentials_give_a_clear_message(): void
    {
        Http::fake(['*/Authentication' => Http::response('"Τα στοιχεία που εισάγατε δεν είναι σωστά."', 401)]);
        $this->company->forceFill(['ergani_username' => 'x', 'ergani_password' => 'y'])->save();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Λάθος κωδικοί e-ΕΦΚΑ');
        (new ErganiClient($this->company->fresh()))->employerInfo();
    }

    public function test_missing_credentials_never_call_out(): void
    {
        Http::fake();

        try {
            (new ErganiClient($this->company))->employerInfo();
            $this->fail('must throw');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Συμπληρώστε', $e->getMessage());
        }
        Http::assertNothingSent();
    }
}
