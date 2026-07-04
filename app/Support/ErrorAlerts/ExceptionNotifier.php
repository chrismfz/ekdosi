<?php

namespace App\Support\ErrorAlerts;

use App\Notifications\UnhandledExceptionAlert;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Throwable;

/**
 * OPS-3 (AUDIT): turns an otherwise-silent unhandled exception into an ops
 * email. Wired from bootstrap/app.php's withExceptions()->report() so it fires
 * for exactly the exceptions Laravel would log (HTTP 4xx / validation / auth
 * are excluded by the framework's don't-report list). It NEVER stops the normal
 * log line and is entirely best-effort — a mail/queue hiccup can't break the
 * failing request or command.
 */
class ExceptionNotifier
{
    /**
     * Entry point from the framework exception handler. Skipped under the test
     * runner so the suite's intentional exceptions don't enqueue alerts; the
     * unit tests exercise notify() directly.
     */
    public function reportFromHandler(Throwable $e): void
    {
        if (app()->runningUnitTests()) {
            return;
        }

        $this->notify(
            $e::class,
            (string) $e->getMessage(),
            $this->relativePath($e->getFile()).':'.$e->getLine(),
            $this->currentContext(),
        );
    }

    /**
     * Send the alert (deduped + throttled). Safe to call directly. Returns
     * early — without ever throwing — when disabled, when there are no
     * recipients, or when an identical error already alerted within the window.
     */
    public function notify(string $exceptionClass, string $message, string $location, string $context): void
    {
        if (! config('ekdosi.error_alerts.enabled', true)) {
            return;
        }

        try {
            $recipients = ExceptionAlertRecipients::resolve();
            if ($recipients === []) {
                return;   // still logged by the normal handler; just no email target
            }

            // Dedupe identical errors (class + message + location) so a tight
            // error loop sends ONE mail per throttle window, not thousands.
            $signature = sha1($exceptionClass.'|'.$message.'|'.$location);
            $ttl = max(1, (int) config('ekdosi.error_alerts.throttle_minutes', 30)) * 60;
            if (! Cache::add('error-alert:'.$signature, 1, $ttl)) {
                return;
            }

            Notification::route('mail', $recipients)->notify(new UnhandledExceptionAlert(
                exceptionClass: $exceptionClass,
                message: Str::limit($message, 500),
                location: $location,
                context: $context,
                occurredAt: now()->format('d/m/Y H:i:s'),
            ));
        } catch (Throwable $e) {
            // The whole point is resilience: an alerting failure must never
            // mask or amplify the original error. Log and move on.
            Log::warning('Exception alert could not be sent: '.$e->getMessage());
        }
    }

    /** Where the exception happened, for the alert's «Πλαίσιο» line. */
    private function currentContext(): string
    {
        if (app()->runningInConsole()) {
            $argv = array_slice($_SERVER['argv'] ?? [], 1);

            return 'CLI: '.($argv === [] ? '(unknown command)' : implode(' ', $argv));
        }

        $request = request();

        return 'HTTP '.strtoupper((string) $request?->method()).' '.(string) $request?->path();
    }

    /** Trim the absolute base path so the location line reads app/… not /var/www/…. */
    private function relativePath(string $absolute): string
    {
        $base = base_path().DIRECTORY_SEPARATOR;

        return str_starts_with($absolute, $base) ? substr($absolute, strlen($base)) : $absolute;
    }
}
