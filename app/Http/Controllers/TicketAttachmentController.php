<?php

namespace App\Http\Controllers;

use App\Models\Scopes\CompanyScope;
use App\Models\Ticket;
use App\Support\TicketAttachments;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Operator download of a ticket attachment (Πυλώνας E, Phase 4 follow-up). AUTH +
 * SIGNED (route middleware) + permission + tenant-checked: the acting user must
 * belong to the ticket's company AND hold View:Ticket, and the attachment must
 * genuinely be one of that ticket's message files — never a public URL, always a
 * forced download (see TicketAttachments). The portal has its own grant-scoped
 * route (PortalTicketController::attachment).
 */
class TicketAttachmentController extends Controller
{
    public function __invoke(int $ticket, int $attachment): StreamedResponse
    {
        $user = auth()->user();
        abort_if($user === null, 403);

        $model = Ticket::query()->withoutGlobalScope(CompanyScope::class)->find($ticket);
        abort_if($model === null, 404);
        // Tenant guard: the user must belong to the ticket's company.
        abort_unless($user->companies()->whereKey($model->company_id)->exists(), 403);

        // Permission gate. This route is OUTSIDE the Filament panel, so the TenantSet
        // listener that normally syncs Spatie's teams team-id never fired — we set it
        // to the ticket's (already tenant-verified) company here, else a non-super-admin
        // operator's team-scoped View:Ticket assignment wouldn't match (null team-id).
        // A company member without the permission still can't pull ticket files,
        // internal-note attachments included. Restore the prior team-id in a finally so
        // this global mutation can't bleed into a later request under a persistent
        // worker (Octane) — harmless under FPM, but the codebase's documented caution.
        $registrar = app(PermissionRegistrar::class);
        $priorTeamId = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId($model->company_id);

        try {
            abort_unless($user->can('View:Ticket'), 403);
        } finally {
            $registrar->setPermissionsTeamId($priorTeamId);
        }

        $file = TicketAttachments::forTicket($model, $attachment);
        abort_if($file === null, 404);

        return TicketAttachments::download($file);
    }
}
