<?php

namespace Tests\Feature\ErrorAlerts;

use App\Notifications\UnhandledExceptionAlert;
use App\Support\ErrorAlerts\ExceptionNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * OPS-3 (AUDIT): unhandled exceptions must reach an ops inbox, not just
 * laravel.log — but deduped so an error loop can't flood it, and never at the
 * cost of the failing request (best-effort).
 */
class ExceptionNotifierTest extends TestCase
{
    use RefreshDatabase;

    private function notifier(): ExceptionNotifier
    {
        return app(ExceptionNotifier::class);
    }

    public function test_it_emails_the_configured_recipient(): void
    {
        Notification::fake();
        config(['ekdosi.error_alerts.enabled' => true, 'ekdosi.error_alerts.email' => 'ops@example.gr']);

        $this->notifier()->notify(\RuntimeException::class, 'boom', 'app/Foo.php:10', 'CLI: test');

        Notification::assertSentOnDemand(
            UnhandledExceptionAlert::class,
            fn ($n, $channels, $notifiable) => in_array('ops@example.gr', (array) ($notifiable->routes['mail'] ?? []), true)
                && $n->exceptionClass === \RuntimeException::class
                && $n->location === 'app/Foo.php:10',
        );
    }

    public function test_identical_errors_are_deduped_within_the_window(): void
    {
        Notification::fake();
        config(['ekdosi.error_alerts.enabled' => true, 'ekdosi.error_alerts.email' => 'ops@example.gr']);

        $this->notifier()->notify(\RuntimeException::class, 'boom', 'app/Foo.php:10', 'ctx');
        $this->notifier()->notify(\RuntimeException::class, 'boom', 'app/Foo.php:10', 'ctx');   // duplicate
        $this->notifier()->notify(\RuntimeException::class, 'different', 'app/Foo.php:10', 'ctx'); // distinct

        Notification::assertSentTimes(UnhandledExceptionAlert::class, 2);
    }

    public function test_disabled_sends_nothing(): void
    {
        Notification::fake();
        config(['ekdosi.error_alerts.enabled' => false, 'ekdosi.error_alerts.email' => 'ops@example.gr']);

        $this->notifier()->notify(\RuntimeException::class, 'boom', 'app/Foo.php:10', 'ctx');

        Notification::assertNothingSent();
    }

    public function test_no_recipients_sends_nothing_and_does_not_throw(): void
    {
        Notification::fake();
        // No dedicated email + no super_admin users → empty recipient set.
        config(['ekdosi.error_alerts.enabled' => true, 'ekdosi.error_alerts.email' => null]);

        $this->notifier()->notify(\RuntimeException::class, 'boom', 'app/Foo.php:10', 'ctx');

        Notification::assertNothingSent();
    }

    public function test_throttle_marker_expires_allowing_a_later_resend(): void
    {
        Notification::fake();
        config([
            'ekdosi.error_alerts.enabled' => true,
            'ekdosi.error_alerts.email' => 'ops@example.gr',
            'ekdosi.error_alerts.throttle_minutes' => 30,
        ]);

        $this->notifier()->notify(\RuntimeException::class, 'boom', 'app/Foo.php:10', 'ctx');

        // Simulate the throttle window elapsing.
        Cache::flush();
        $this->notifier()->notify(\RuntimeException::class, 'boom', 'app/Foo.php:10', 'ctx');

        Notification::assertSentTimes(UnhandledExceptionAlert::class, 2);
    }
}
