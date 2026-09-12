<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\Company;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The admin-side 2FA visibility/management on the Users resource: a «2FA»
 * column + filter on the list, and a status + «Επαναφορά 2FA» on the edit page.
 * Enabling 2FA stays self-service (profile QR) — admins can only see/reset it.
 */
class UserTwoFactorAdminTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        Gate::before(fn () => true); // act as an all-permissions super-admin

        // The admin panel is tenant-scoped, so even the (panel-level) Users
        // resource routes carry {tenant} — a current tenant is needed to render
        // the pages/build URLs.
        $this->tenant = Company::create([
            'name' => '2FA OE', 'slug' => '2fa-'.uniqid(),
            'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'sandbox',
            'afm' => '800000000',
        ]);

        // setTenant fires TenantSet(user) → an authed user must exist first.
        $this->actingAs($this->user('admin-'.uniqid().'@t.local', with2fa: false));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($this->tenant);
    }

    private function user(string $email, bool $with2fa): User
    {
        $user = User::create([
            'name' => 'U '.$email, 'email' => $email, 'password' => bcrypt('x'),
        ]);
        $user->companies()->attach($this->tenant->id);

        if ($with2fa) {
            $user->saveAppAuthenticationSecret('S3CR3TBASE32AAAA');
            $user->saveAppAuthenticationRecoveryCodes(['aaaa-bbbb', 'cccc-dddd']);
        }

        return $user;
    }

    public function test_reset_2fa_on_edit_page_clears_the_secret(): void
    {
        $target = $this->user('enrolled-'.uniqid().'@t.local', with2fa: true);

        Livewire::test(EditUser::class, ['record' => $target->getKey()])
            ->assertActionVisible('reset_2fa')
            ->callAction('reset_2fa');

        $fresh = $target->fresh();
        $this->assertNull($fresh->getAppAuthenticationSecret());
        $this->assertNull($fresh->getAppAuthenticationRecoveryCodes());
    }

    public function test_reset_2fa_is_hidden_when_the_user_is_not_enrolled(): void
    {
        $target = $this->user('plain-'.uniqid().'@t.local', with2fa: false);

        Livewire::test(EditUser::class, ['record' => $target->getKey()])
            ->assertActionHidden('reset_2fa');
    }

    public function test_list_two_factor_filter_splits_enrolled_from_plain(): void
    {
        $enrolled = $this->user('has2fa-'.uniqid().'@t.local', with2fa: true);
        $plain = $this->user('no2fa-'.uniqid().'@t.local', with2fa: false);

        Livewire::test(ListUsers::class)
            ->filterTable('two_factor', true)
            ->assertCanSeeTableRecords([$enrolled])
            ->assertCanNotSeeTableRecords([$plain]);

        Livewire::test(ListUsers::class)
            ->filterTable('two_factor', false)
            ->assertCanSeeTableRecords([$plain])
            ->assertCanNotSeeTableRecords([$enrolled]);
    }
}
