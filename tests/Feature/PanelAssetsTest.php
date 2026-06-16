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
}
