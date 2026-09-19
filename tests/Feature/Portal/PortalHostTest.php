<?php

namespace Tests\Feature\Portal;

use App\Models\Company;
use App\Support\CustomerLanguage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Multi-domain #1c (Option A "soft"): a custom `companies.portal_host` pins the
 * tenant for the portal request, so the GUEST pages render in that tenant's
 * language + show its branding. Same URLs; data access stays per-grant.
 */
class PortalHostTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(array $attrs = []): Company
    {
        return Company::create(array_merge([
            'name' => 'Nixpal OÜ',
            'slug' => 'nixpal-'.uniqid(),
            'country_code' => 'EE',
            'einvoice_provider' => 'none',
            'mydata_mode' => 'off',
        ], $attrs));
    }

    public function test_resolve_by_portal_host_is_case_insensitive_and_null_safe(): void
    {
        $company = $this->tenant(['portal_host' => 'cs.nixpal.com']);

        $this->assertTrue(Company::resolveByPortalHost('CS.Nixpal.COM')?->is($company));
        $this->assertTrue(Company::resolveByPortalHost('  cs.nixpal.com ')?->is($company));
        $this->assertNull(Company::resolveByPortalHost('invoicer.myip.gr'));
        $this->assertNull(Company::resolveByPortalHost(''));
        $this->assertNull(Company::resolveByPortalHost(null));
    }

    public function test_portal_host_is_normalised_lowercase_on_write(): void
    {
        $company = $this->tenant(['portal_host' => '  CS.Nixpal.COM  ']);
        $this->assertSame('cs.nixpal.com', $company->fresh()->portal_host);

        // Blank → null (not an empty configured host).
        $company->update(['portal_host' => '   ']);
        $this->assertNull($company->fresh()->portal_host);
    }

    public function test_for_host_maps_tenant_default_to_ui_locale(): void
    {
        config(['app.locale' => 'el']);

        $this->assertSame('el', CustomerLanguage::forHost($this->tenant(['default_language' => 'el'])));
        $this->assertSame('en', CustomerLanguage::forHost($this->tenant(['default_language' => 'en'])));
        // Bilingual tenant → English guest chrome (single-language UI).
        $this->assertSame('en', CustomerLanguage::forHost($this->tenant(['default_language' => 'both'])));
        // No tenant / no default → app default.
        $this->assertSame('el', CustomerLanguage::forHost($this->tenant(['default_language' => null])));
        $this->assertSame('el', CustomerLanguage::forHost(null));
    }

    public function test_guest_login_renders_tenant_language_and_branding_on_custom_host(): void
    {
        $this->tenant(['portal_host' => 'cs.nixpal.com', 'default_language' => 'en']);

        $this->get('http://cs.nixpal.com/user/login')
            ->assertOk()
            ->assertSee('Customer sign-in')     // en chrome (forHost → en)
            ->assertSee('Nixpal OÜ')             // branding eyebrow
            ->assertDontSee('Είσοδος πελατών');  // Greek chrome absent
    }

    public function test_login_error_is_localised_on_a_custom_host(): void
    {
        // finding-2 fix: the failed-login message follows the host locale too, so
        // an English portal host doesn't show a Greek error under English chrome.
        $this->tenant(['portal_host' => 'cs.nixpal.com', 'default_language' => 'en']);

        $this->from('http://cs.nixpal.com/user/login')
            ->post('http://cs.nixpal.com/user/login', ['email' => 'nobody@example.com', 'password' => 'wrong'])
            ->assertRedirect()
            ->assertSessionHasErrors(['email' => 'Wrong email or password.']);
    }

    public function test_guest_login_defaults_to_greek_on_the_shared_host(): void
    {
        config(['app.locale' => 'el']);   // pin: assert against the app default, not ambient CI locale

        // A tenant exists but the request host does not match its portal_host.
        $this->tenant(['portal_host' => 'cs.nixpal.com', 'default_language' => 'en']);

        $this->get('http://invoicer.myip.gr/user/login')
            ->assertOk()
            ->assertSee('Είσοδος πελατών')       // app default (el)
            ->assertDontSee('Customer sign-in')
            ->assertDontSee('Nixpal OÜ');        // no branding on the shared host
    }
}
