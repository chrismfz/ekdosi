<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsurePortalAuthenticated;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Portal profile (Slice-0 prototype): the customer edits their OWN account —
 * display name, phone, locale, and password. The email (login identity) is
 * read-only here (changing it needs re-verification, a later slice), and there
 * is deliberately no ΑΦΜ/legal-identity editing (that lives on `customers` and
 * is the operator's domain). Everything is scoped to the logged-in portal user.
 */
class ProfileController extends Controller
{
    public function show(Request $request): View
    {
        return view('portal.profile', ['user' => Auth::guard('portal')->user()]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'phone' => ['nullable', 'string', 'max:40'],
            'locale' => ['nullable', 'string', 'in:el,en'],
        ]);

        Auth::guard('portal')->user()->forceFill($data)->save();

        return back()->with('status', __('portal.flash.profile_saved'));
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $user = Auth::guard('portal')->user();

        $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        if (! Hash::check((string) $request->input('current_password'), (string) $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => __('Ο τρέχων κωδικός δεν είναι σωστός.'),
            ]);
        }

        $user->forceFill([
            'password' => $request->input('password'),   // 'hashed' cast hashes it
            'password_changed_at' => now(),
            // Rotate the remember token so any old «remember me» cookies (e.g. on a
            // compromised device — the reason to change a password) stop working.
            'remember_token' => Str::random(60),
        ])->save();

        // Keep THIS session valid but with a fresh id (defense against fixation).
        $request->session()->regenerate();
        // Re-bind THIS session to the new hash, so the session-invalidation check
        // (EnsurePortalAuthenticated) logs out the customer's OTHER sessions on
        // their next request while this one survives.
        $request->session()->put(EnsurePortalAuthenticated::PW_HASH_KEY, (string) $user->getAuthPassword());

        return back()->with('status', __('portal.flash.password_changed'));
    }
}
