<?php

namespace App\Http\Middleware;

use App\Filament\Resources\LeaveRequests\LeaveRequestResource;
use App\Models\Company;
use App\Support\Hr\ErganiStaff;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Confines the `ergani` role («Προσωπικό — μόνο άδειες») to the leave screens.
 * DEFAULT-DENY: any tenant route not on the allowlist — including pages added
 * in the future that forget a permission check (the dashboard widgets, the
 * mydata reconciliation page, …) — redirects to «Άδειες». Registered as a
 * persistent tenant middleware, so Livewire updates of a page re-check it too.
 * Operators/admins pass straight through.
 */
class RestrictErganiStaff
{
    /** Route-name fragments an ergani user may open (panel-id agnostic). */
    private const ALLOWED = [
        '.resources.leave-requests.',
        '.pages.leave-calendar',
        // Per-USER self-service (their own login sessions) — no tenant data.
        '.pages.my-sessions',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = Filament::getTenant();

        if (! $tenant instanceof Company || ! ErganiStaff::isRestricted($request->user(), $tenant)) {
            return $next($request);
        }

        $name = (string) $request->route()?->getName();
        foreach (self::ALLOWED as $fragment) {
            if (str_contains($name, $fragment)) {
                return $next($request);
            }
        }

        // Livewire update of a page they may not see, or a non-GET → hard stop.
        if (! $request->isMethod('GET') || $request->hasHeader('X-Livewire')) {
            abort(403);
        }

        return redirect()->to(LeaveRequestResource::getUrl('index', tenant: $tenant));
    }
}
