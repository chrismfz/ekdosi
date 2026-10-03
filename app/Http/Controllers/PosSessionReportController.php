<?php

namespace App\Http\Controllers;

use App\Models\PosSession;
use App\Models\Scopes\CompanyScope;
use App\Services\Pos\TillSessions;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\URL;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * The «Ταμείο ημέρας» report (POS PR 2b) — 80mm, printed at closing (or an X-style
 * snapshot of the open session). INTERNAL: not a legal Ζ. AUTH + SIGNED + tenant-
 * checked, like the till receipt (PosReceiptController).
 */
class PosSessionReportController extends Controller
{
    public static function signedUrl(int $sessionId, int $minutes = 30): string
    {
        return URL::temporarySignedRoute('pos.session-report', now()->addMinutes($minutes), ['session' => $sessionId]);
    }

    public function __invoke(Request $request, int $session, TillSessions $tills): Response
    {
        $posSession = PosSession::query()->withoutGlobalScope(CompanyScope::class)
            ->with(['company', 'opener', 'closer'])
            ->findOrFail($session);

        $user = $request->user();
        abort_unless($user !== null && $user->companies()->whereKey($posSession->company_id)->exists(), HttpResponse::HTTP_FORBIDDEN);

        // Outside the panel: set Spatie's team id for the check (see PosReceiptController).
        $registrar = app(PermissionRegistrar::class);
        $priorTeamId = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId($posSession->company_id);
        try {
            abort_unless($user->can('View:PointOfSale') || $user->can('View:Invoice'), HttpResponse::HTTP_FORBIDDEN);
        } finally {
            $registrar->setPermissionsTeamId($priorTeamId);
        }

        return response()
            ->view('pos.session-report', ['session' => $posSession, 'report' => $tills->report($posSession)])
            ->header('Cache-Control', 'private, no-store');
    }
}
