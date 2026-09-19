<?php

namespace Tests\Feature\Portal;

use App\Http\Middleware\SetPortalLocale;
use App\Models\CustomerUser;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Tests\TestCase;

/**
 * SetPortalLocale picks the request locale from the logged-in customer user's
 * stored preference (i18n Slice 0). No DB — the guard user is set in-memory via
 * actingAs, the middleware only reads it.
 */
class PortalLocaleTest extends TestCase
{
    private function runMiddleware(): void
    {
        (new SetPortalLocale)->handle(
            Request::create('/user', 'GET'),
            fn (Request $request): Response => new Response('ok'),
        );
    }

    public function test_sets_locale_from_logged_in_customer_user(): void
    {
        app()->setLocale('el');
        $this->actingAs(new CustomerUser(['locale' => 'en']), 'portal');

        $this->runMiddleware();

        $this->assertSame('en', app()->getLocale());
    }

    public function test_resets_a_wrong_baseline_to_the_users_preference(): void
    {
        // Prove the middleware DRIVES the locale from the user, not the ambient
        // request state: an 'en' baseline is overridden by an 'el' user.
        app()->setLocale('en');
        $this->actingAs(new CustomerUser(['locale' => 'el']), 'portal');

        $this->runMiddleware();

        $this->assertSame('el', app()->getLocale());
    }

    public function test_guest_keeps_a_valid_ui_locale_without_crashing(): void
    {
        // No actingAs → no portal user. forUi(null) resolves the app default, and
        // the middleware must not blow up on a missing user.
        $this->runMiddleware();

        $this->assertContains(app()->getLocale(), ['el', 'en']);
    }
}
