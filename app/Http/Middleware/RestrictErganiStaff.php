<?php

namespace App\Http\Middleware;

use App\Filament\Pages\PointOfSale;
use App\Filament\Resources\LeaveRequests\LeaveRequestResource;
use App\Models\Company;
use App\Services\TenantRoleProvisioner;
use App\Support\Hr\ErganiStaff;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Confines the `ergani` role («Προσωπικό — μόνο άδειες») to the leave screens —
 * and the `cashier` role («Ταμίας», POS PR 2c) to the till plus the same staff screens.
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

    /** …plus, for a cashier, the till (exact page — its receipt/report routes are outside the panel). */
    private const CASHIER_PAGES = [
        '.pages.point-of-sale',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = Filament::getTenant();

        $role = $tenant instanceof Company ? ErganiStaff::restrictedRole($request->user(), $tenant) : null;
        if ($role === null) {
            return $next($request);
        }
        $cashier = $role === TenantRoleProvisioner::ROLE_CASHIER;

        $name = (string) $request->route()?->getName();
        foreach (self::ALLOWED_PREFIXES as $fragment) {
            if (str_contains($name, $fragment)) {
                return $next($request);
            }
        }
        foreach ($cashier ? [...self::ALLOWED_PAGES, ...self::CASHIER_PAGES] : self::ALLOWED_PAGES as $page) {
            if (str_ends_with($name, $page)) {   // exact page — a future «work-card-x» stays denied
                return $next($request);
            }
        }

        // Livewire update of a page they may not see, or a non-GET → hard stop.
        if (! $request->isMethod('GET') || $request->hasHeader('X-Livewire')) {
            abort(403);
        }

        // A cashier lands on the till — unless it isn't usable (till switched off):
        // then on their own leave screens, never on a 403 dead end.
        return redirect()->to($cashier && PointOfSale::canAccess()
            ? PointOfSale::getUrl(tenant: $tenant)
            : LeaveRequestResource::getUrl('index', tenant: $tenant));
    }
}
