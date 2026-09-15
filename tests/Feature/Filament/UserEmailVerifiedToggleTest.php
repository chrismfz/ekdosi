<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Models\Company;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Regression: the «Email verified» toggle on the Users resource must actually
 * persist `email_verified_at`. It used to be a proxy toggle that fed a HIDDEN
 * DateTimePicker — and a hidden field is not dehydrated by default in Filament,
 * so the save silently dropped it (the «Αποθηκεύτηκε» toast fired but the user
 * stayed unverified). The toggle now binds directly to `email_verified_at`.
 */
class UserEmailVerifiedToggleTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        Gate::before(fn () => true); // act as an all-permissions super-admin

        $this->tenant = Company::create([
            'name' => 'Verify OE', 'slug' => 'verify-'.uniqid(),
            'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'sandbox',
            'afm' => '800000000',
        ]);

        $this->actingAs($this->user('admin-'.uniqid().'@t.local'));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($this->tenant);
    }

    private function user(string $email): User
    {
        $user = User::create(['name' => 'U '.$email, 'email' => $email, 'password' => bcrypt('x')]);
        $user->companies()->attach($this->tenant->id);

        return $user;
    }

    public function test_toggling_email_verified_on_persists_the_timestamp(): void
    {
        $target = $this->user('unverified-'.uniqid().'@t.local');
        $this->assertNull($target->email_verified_at, 'starts unverified');

        Livewire::test(EditUser::class, ['record' => $target->getKey()])
            ->fillForm(['email_verified_at' => true])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertNotNull($target->fresh()->email_verified_at, 'toggle ON writes the verified timestamp');
    }

    public function test_toggling_email_verified_off_unverifies(): void
    {
        $target = $this->user('verified-'.uniqid().'@t.local');
        $target->forceFill(['email_verified_at' => now()])->save();

        Livewire::test(EditUser::class, ['record' => $target->getKey()])
            ->fillForm(['email_verified_at' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertNull($target->fresh()->email_verified_at, 'toggle OFF clears the timestamp');
    }

    public function test_an_unrelated_save_does_not_bump_the_verified_timestamp(): void
    {
        $when = now()->subDays(3)->startOfSecond();
        $target = $this->user('keep-'.uniqid().'@t.local');
        $target->forceFill(['email_verified_at' => $when])->save();

        Livewire::test(EditUser::class, ['record' => $target->getKey()])
            ->fillForm(['name' => 'Renamed'])   // toggle untouched
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue(
            $target->fresh()->email_verified_at->equalTo($when),
            'an unrelated edit keeps the original verified-at, does not reset it to now()'
        );
    }

    public function test_creating_a_user_with_the_toggle_on_is_verified(): void
    {
        $email = 'newop-'.uniqid().'@t.local';

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'New Op', 'email' => $email, 'password' => 'password123',
                'email_verified_at' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertNotNull(User::where('email', $email)->firstOrFail()->email_verified_at);
    }
}
