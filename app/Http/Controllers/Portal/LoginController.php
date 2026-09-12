<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\CustomerUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Customer-portal auth (Slice 0): login / logout on the `portal` guard. No
 * registration or password-reset yet — logins are minted by `portal:create-user`
 * (and, later, by operator invite / claim). Kept intentionally small and
 * self-contained; the operator/Filament auth is entirely separate.
 */
class LoginController extends Controller
{
    /**
     * A fixed bcrypt hash (cost 12 — the app default) that no real password
     * matches. Used only to spend one hash-check on the «no such account» login
     * path, so timing doesn't reveal which e-mails are registered (see
     * equalizeFailedLoginTiming). Not a secret — it hashes a throwaway string.
     */
    private const TIMING_EQUALIZER_HASH = '$2y$12$GsBPTC0jX5KqQsIDPWHDWulDmxDSK27WSsY3M7SPVL1hdLKVqmV66';

    public function show(Request $request): View|RedirectResponse
    {
        if (Auth::guard('portal')->check()) {
            return redirect()->route('portal.home');
        }

        return view('portal.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        $remember = $request->boolean('remember');

        // One generic failure for every reason (wrong password, unknown email,
        // suspended, invited-without-password) — no account enumeration or status
        // disclosure. A null-password row can't match here anyway.
        if (! Auth::guard('portal')->attempt($credentials, $remember)) {
            $this->equalizeFailedLoginTiming($credentials['email'], $credentials['password']);

            throw ValidationException::withMessages([
                'email' => __('Λάθος email ή κωδικός.'),
            ]);
        }

        $user = Auth::guard('portal')->user();
        if ($user === null || ! $user->canLogin()) {
            Auth::guard('portal')->logout();

            throw ValidationException::withMessages([
                'email' => __('Λάθος email ή κωδικός.'),
            ]);
        }

        // Prevent session fixation, then record the (denormalised) last-login
        // snapshot without tripping model events/observers.
        $request->session()->regenerate();
        $user->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ])->saveQuietly();

        return redirect()->intended(route('portal.home'));
    }

    /**
     * Close the account-enumeration timing gap on a failed login: Auth::attempt()
     * runs bcrypt ONLY when the e-mail matches a row, so an unknown e-mail answers
     * measurably faster and leaks which addresses are registered. When nothing
     * matched, spend one equivalent hash-check so both the «wrong password» and
     * the «no such account» paths cost ~one bcrypt. (Modest value behind the
     * per-IP/route throttle + a CDN, but the gap is real and the fix is cheap.)
     */
    private function equalizeFailedLoginTiming(string $email, string $password): void
    {
        if (! CustomerUser::where('email', $email)->exists()) {
            Hash::check($password, self::TIMING_EQUALIZER_HASH);
        }
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('portal')->logout();

        // Full invalidate() — DESTROY the session server-side, so a stolen/hijacked
        // pre-logout session cookie stops authenticating the moment the user logs
        // out (regenerate() alone would leave the old id alive in the store). The
        // session record is shared with the operator 'web' guard, so this also ends
        // a co-logged-in operator's /admin session in the same browser — the correct
        // trade-off (see config/auth.php: secure logout > a rare same-browser combo).
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('portal.login');
    }
}
