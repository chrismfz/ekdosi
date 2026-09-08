<?php

namespace Tests\Feature\Settings;

use App\Models\Company;
use App\Models\User;
use App\Notifications\UnhandledExceptionAlert;
use App\Services\Assistant\AssistantRunner;
use App\Services\Updates\UpdateChecker;
use App\Support\ErrorAlerts\ExceptionNotifier;
use App\Support\Settings\SystemSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The «Ρυθμίσεις συστήματος» UI writes `system_settings` overrides; these prove the
 * READERS honour them — env/config stays the default, a stored row wins. Without
 * this wiring the toggles would be cosmetic.
 */
class SystemSettingsReaderOverridesTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function update_check_override_disables_even_when_config_is_on(): void
    {
        config(['ekdosi.updates.enabled' => true]);
        app(SystemSettings::class)->setBool('system.update_check_enabled', false);

        $this->assertFalse(app(UpdateChecker::class)->check()['enabled']);
    }

    #[Test]
    public function error_alerts_send_by_default_but_the_override_suppresses_them(): void
    {
        Notification::fake();
        config(['ekdosi.error_alerts.enabled' => true, 'ekdosi.error_alerts.email' => 'ops@x.gr']);

        // No override → tracks the config default (ON): the alert goes out.
        app(ExceptionNotifier::class)->notify('X', 'boom-a', 'app/Foo.php:1', 'test');
        Notification::assertSentOnDemand(UnhandledExceptionAlert::class);

        // Override OFF → suppressed even though config is ON (distinct signature so
        // dedupe can't be the reason nothing is sent).
        Notification::fake();
        app(SystemSettings::class)->setBool('system.error_alerts_enabled', false);
        app(ExceptionNotifier::class)->notify('X', 'boom-b', 'app/Foo.php:2', 'test');
        Notification::assertNothingSent();
    }

    #[Test]
    public function ai_master_switch_override_disables_even_when_config_and_tenant_are_on(): void
    {
        config(['ekdosi.ai.enabled' => true, 'services.anthropic.key' => 'sk-test']);
        $user = User::create([
            'name' => 'U', 'email' => 'u-'.uniqid().'@test.local', 'password' => bcrypt('x'),
        ]);
        $tenant = Company::create([
            'name' => 'AI OE', 'slug' => 'ai-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
            'ai_assistant_enabled' => true,
        ]);

        app(SystemSettings::class)->setBool('system.ai_enabled', false);

        // Master switch OFF → refused before any HTTP call, despite config + tenant ON.
        $result = app(AssistantRunner::class)->ask($tenant, $user, 'γεια');

        $this->assertTrue($result['blocked']);
    }
}
