<?php

namespace Tests\Feature\Portal;

use App\Models\CustomerUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Portal profile: the customer edits their OWN account (name/phone/locale) and
 * changes their password. Email/ΑΦΜ are not editable here.
 */
class PortalProfileTest extends TestCase
{
    use RefreshDatabase;

    private function user(): CustomerUser
    {
        return CustomerUser::factory()->create([
            'email' => 'pelatis@example.com',
            'password' => Hash::make('secret-pass-123'),
            'status' => CustomerUser::STATUS_ACTIVE,
            'name' => 'Αρχικό Όνομα',
        ]);
    }

    public function test_profile_page_renders(): void
    {
        $this->actingAs($this->user(), 'portal')
            ->get('/user/settings')
            ->assertOk()
            ->assertSee('Τα στοιχεία μου');
    }

    public function test_portal_renders_in_english_for_an_en_locale_user(): void
    {
        // i18n Portal slice: SetPortalLocale reads customer_users.locale and the
        // blades resolve through __('portal.*'), so an 'en' user sees English UI
        // (same URL) while Greek strings are absent.
        $user = CustomerUser::factory()->create([
            'email' => 'ee@example.com',
            'password' => Hash::make('secret-pass-123'),
            'status' => CustomerUser::STATUS_ACTIVE,
            'locale' => 'en',
        ]);

        $this->actingAs($user, 'portal')
            ->get('/user/settings')
            ->assertOk()
            ->assertSee('My details')          // profile title + nav (en)
            ->assertSee('Sign out')            // layout nav (en)
            ->assertDontSee('Τα στοιχεία μου') // Greek title absent
            ->assertDontSee('Αποσύνδεση');     // Greek nav absent
    }

    public function test_updates_own_details(): void
    {
        $user = $this->user();

        $this->actingAs($user, 'portal')
            ->post('/user/settings', ['name' => 'Νέο Όνομα', 'phone' => '2101234567', 'locale' => 'en'])
            ->assertRedirect()
            ->assertSessionHas('status');

        $user->refresh();
        $this->assertSame('Νέο Όνομα', $user->name);
        $this->assertSame('2101234567', $user->phone);
        $this->assertSame('en', $user->locale);
    }

    public function test_changes_password_with_correct_current(): void
    {
        $user = $this->user();

        $this->actingAs($user, 'portal')
            ->post('/user/settings/password', [
                'current_password' => 'secret-pass-123',
                'password' => 'a-new-strong-pass',
                'password_confirmation' => 'a-new-strong-pass',
            ])
            ->assertSessionHas('status');

        $user->refresh();
        $this->assertTrue(Hash::check('a-new-strong-pass', $user->password));
        $this->assertNotNull($user->password_changed_at);
    }

    public function test_rejects_password_change_with_wrong_current(): void
    {
        $user = $this->user();

        $this->actingAs($user, 'portal')
            ->from('/user/settings')
            ->post('/user/settings/password', [
                'current_password' => 'WRONG',
                'password' => 'a-new-strong-pass',
                'password_confirmation' => 'a-new-strong-pass',
            ])
            ->assertSessionHasErrors('current_password');

        $this->assertTrue(Hash::check('secret-pass-123', $user->refresh()->password));
    }
}
