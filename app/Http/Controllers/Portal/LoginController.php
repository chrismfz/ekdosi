<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('portal')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('portal.login');
    }
}
