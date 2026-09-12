<?php

namespace App\Listeners;

use App\Models\AuthEvent;
use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Records login / logout / failed-attempt rows into `auth_events` for BOTH
 * panels (web = /admin, portal = /user), so a super-admin can see who signed in
 * and — crucially — spot recon / brute-force against real OR non-existent
 * usernames. Laravel's SessionGuard::attempt() fires Failed on every bad attempt
 * (with the tried identifier, user null when it doesn't exist), which is the
 * signal; repeated Failed rows from one IP == someone «μας έβαλε στο μάτι».
 *
 * We deliberately do NOT listen for Lockout: the app's throttling (Filament's
 * rate limiter, the portal's generic failure) doesn't fire it, and the Failed
 * rows already carry the brute-force picture.
 *
 * Every write is best-effort (try/catch) — auth logging must NEVER block a login
 * or a logout, even if the table is missing or the DB hiccups.
 */
class RecordAuthEvent
{
    public function handleLogin(Login $event): void
    {
        $this->record('login', $event->guard, $event->user?->getAuthIdentifier(), $this->emailOf($event->user));

        // Denormalised last-login snapshot on operator accounts (customer_users
        // gets its own in the portal LoginController). saveQuietly: a bookkeeping
        // write, no model events / activity-log entry.
        if ($event->user instanceof User) {
            try {
                $event->user->forceFill([
                    'last_login_at' => now(),
                    'last_login_ip' => request()->ip(),
                ])->saveQuietly();
            } catch (\Throwable) {
                // best-effort — never block login on a bookkeeping write
            }
        }
    }

    public function handleLogout(Logout $event): void
    {
        $this->record('logout', $event->guard, $event->user?->getAuthIdentifier(), $this->emailOf($event->user));
    }

    public function handleFailed(Failed $event): void
    {
        // The tried identifier — an attacker-supplied string for a non-existent
        // account. The password (also in $credentials) is NEVER read/stored.
        $email = $event->credentials['email'] ?? $event->credentials['username'] ?? null;

        $this->record('failed', $event->guard, $event->user?->getAuthIdentifier(), is_string($email) ? $email : null);
    }

    private function emailOf(mixed $user): ?string
    {
        return is_object($user) && isset($user->email) ? (string) $user->email : null;
    }

    private function record(string $eventName, ?string $guard, mixed $userId, ?string $email): void
    {
        try {
            AuthEvent::create([
                'guard' => (string) ($guard ?? 'web'),
                'event' => $eventName,
                'user_id' => is_numeric($userId) ? (int) $userId : null,
                'email' => $email !== null ? mb_substr($email, 0, 255) : null,
                'ip_address' => request()->ip(),
                'user_agent' => ($ua = request()->userAgent()) !== null ? mb_substr($ua, 0, 1000) : null,
                'created_at' => now(),
            ]);
        } catch (\Throwable) {
            // Strictly best-effort: a DB hiccup (or an un-migrated table on a
            // half-deployed host) must never break authentication.
        }
    }

    /**
     * @return array<class-string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            Login::class => 'handleLogin',
            Logout::class => 'handleLogout',
            Failed::class => 'handleFailed',
        ];
    }
}
