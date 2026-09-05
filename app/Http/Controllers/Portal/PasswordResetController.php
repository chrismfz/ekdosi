<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\CustomerUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Customer-portal password reset — also the invited-login «claim» (an
 * operator-invited login sets its first password through the same flow, and is
 * activated on success). Uses the dedicated `customer_users` broker/token table,
 * fully separate from the operator reset.
 *
 * Anti-abuse: the request endpoint always returns ONE generic message (no
 * account enumeration), carries a honeypot, and is throttled per-email AND
 * per-IP on top of the broker's own 60s throttle — the email side caps
 * inbox-bombing a victim, the IP side caps mass abuse from one source.
 */
class PasswordResetController extends Controller
{
    /** The single response for every request outcome — no existence/status disclosure. */
    private const GENERIC = 'Αν το email αντιστοιχεί σε λογαριασμό, σου στείλαμε σύνδεσμο για να ορίσεις κωδικό.';

    private const MAX_PER_EMAIL = 5;      // per hour, per email address

    public function showLinkRequest(): View
    {
        return view('portal.password.forgot');
    }

    public function sendLink(Request $request): RedirectResponse
    {
        // Honeypot: a hidden field humans never see. A bot that fills it gets the
        // same generic response (no signal it was caught) and no mail is sent.
        // Named `fax` (not a website/email/name field) so browser & password-manager
        // autofill heuristics don't populate it and silently block a real user.
        if (filled($request->input('fax'))) {
            return back()->with('status', self::GENERIC);
        }

        $request->validate(['email' => ['required', 'string', 'email']]);
        $email = mb_strtolower(trim((string) $request->input('email')));

        // Per-email cap (inbox-bombing a specific victim). The route middleware
        // adds the per-IP cap. On limit we still return the generic message so a
        // caller can't distinguish throttling from a normal send.
        $key = 'portal-pwreset:'.sha1($email);
        if (RateLimiter::tooManyAttempts($key, self::MAX_PER_EMAIL)) {
            return back()->with('status', self::GENERIC);
        }
        RateLimiter::hit($key, 3600);

        // Response body + redirect are identical for every outcome, and the mail is
        // queued so send time is off the request path. (A real hit still does a
        // token-table write, so timing isn't perfectly constant-time — an accepted
        // residual, far below the signal the old distinct messages would give.)
        Password::broker('customer_users')->sendResetLink(['email' => $email]);

        return back()->with('status', self::GENERIC);
    }

    public function showReset(Request $request, string $token): View
    {
        return view('portal.password.reset', [
            'token' => $token,
            'email' => (string) $request->query('email', ''),
        ]);
    }

    public function reset(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ]);

        $status = Password::broker('customer_users')->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (CustomerUser $user, string $password): void {
                $user->forceFill([
                    'password' => $password,   // 'hashed' cast hashes it
                    'password_changed_at' => now(),
                    // Rotate the remember token so any old «remember me» cookies stop
                    // working (a reset is the moment to assume compromise).
                    'remember_token' => Str::random(60),
                    // The claim: an operator-invited login activates itself by
                    // setting its first password. Any other status is left as-is
                    // (a suspended login stays suspended — still can't log in).
                    'status' => $user->status === CustomerUser::STATUS_INVITED
                        ? CustomerUser::STATUS_ACTIVE
                        : $user->status,
                ])->save();
            }
        );

        if ($status === Password::PasswordReset) {
            return redirect()->route('portal.login')
                ->with('status', 'Ο κωδικός ορίστηκε. Μπορείς να συνδεθείς.');
        }

        // ONE generic error for every failure (unknown email OR bad/expired token
        // alike). The broker's own messages differ (INVALID_USER vs INVALID_TOKEN),
        // which would let this endpoint enumerate logins — so we never surface them;
        // it stays as tight-lipped as the request endpoint.
        throw ValidationException::withMessages([
            'email' => ['Ο σύνδεσμος επαναφοράς είναι άκυρος ή έληξε. Ζήτησε νέο σύνδεσμο.'],
        ]);
    }
}
