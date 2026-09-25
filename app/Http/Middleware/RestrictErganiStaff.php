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
    /** Route names an ergani user may open: a resource PREFIX, or an EXACT page. */
    private const ALLOWED_PREFIXES = [
        '.resources.leave-requests.',
    ];

    private const ALLOWED_PAGES = [
        '.pages.leave-calendar',
        '.pages.work-card',     // their own punch screen (the kiosk is «card-kiosk»)
        '.pages.my-sessions',   // per-USER self-service (their own login sessions)
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = Filament::getTenant();

        if (! $tenant instanceof Company || ! ErganiStaff::isRestricted($request->user(), $tenant)) {
            return $next($request);
        }

        $name = (string) $request->route()?->getName();
        foreach (self::ALLOWED_PREFIXES as $fragment) {
            if (str_contains($name, $fragment)) {
                return $next($request);
            }
        }
        foreach (self::ALLOWED_PAGES as $page) {
            if (str_ends_with($name, $page)) {   // exact page — a future «work-card-x» stays denied
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
