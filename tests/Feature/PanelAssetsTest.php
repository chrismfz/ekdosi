<?php

namespace Tests\Feature;

use Filament\Support\Facades\FilamentAsset;
use Tests\TestCase;

/**
 * The no-build panel utility CSS (resources/css/panel.css) is registered with
 * FilamentAsset and injected into the admin panel <head> — so custom blade pages
 * get their utility styling without a Tailwind build. Guards the wiring.
 */
class PanelAssetsTest extends TestCase
{
    public function test_panel_css_is_registered(): void
    {
        $href = FilamentAsset::getStyleHref('ekdosi-panel');

        $this->assertStringContainsString('ekdosi-panel.css', $href);
    }

    public function test_login_page_links_the_panel_css(): void
    {
        // The login page is a panel page → Filament renders the registered styles.
        $response = $this->get('/admin/login');

        $response->assertOk();
        $response->assertSee('ekdosi-panel.css', escape: false);
    }

    public function test_high_traffic_utilities_are_defined(): void
    {
        // Cheap completeness guard: the utilities most used across custom pages
        // must be defined, or those pages render unstyled with a green suite.
        // `overflow-x-auto` (the wide-table scroll container) is the #1 by usage.
        $css = file_get_contents(resource_path('css/panel.css'));

        foreach ([
            '.overflow-x-auto', '.flex', '.grid', '.grid-cols-3', '.gap-x-4',
            '.flex-wrap', '.rounded-full', '.bg-success-100', '.text-sm', '.pr-4',
        ] as $selector) {
            $this->assertStringContainsString($selector.' ', $css, "panel.css must define {$selector}");
        }
    }
}
