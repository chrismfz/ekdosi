<?php

namespace App\Http\Controllers;

use App\Models\Scopes\CompanyScope;
use App\Models\Ticket;
use App\Support\TicketAttachments;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Operator download of a ticket attachment (Πυλώνας E, Phase 4 follow-up). AUTH +
 * tenant-checked: the acting user must belong to the ticket's company, and the
 * attachment must genuinely be one of that ticket's message files — never a public
 * URL, always a forced download (see TicketAttachments). The portal has its own
 * grant-scoped route (PortalTicketController::attachment).
 */
class TicketAttachmentController extends Controller
{
    public function __invoke(int $ticket, int $attachment): StreamedResponse
    {
        $user = auth()->user();
        abort_if($user === null, 403);
        // Permission gate (same as the expense-document sibling): a company member
        // without the tickets permission must not be able to pull ticket files —
        // internal-note attachments included.
        abort_unless($user->can('View:Ticket'), 403);

        $model = Ticket::query()->withoutGlobalScope(CompanyScope::class)->find($ticket);
        abort_if($model === null, 404);
        abort_unless($user->companies()->whereKey($model->company_id)->exists(), 403);

        $file = TicketAttachments::forTicket($model, $attachment);
        abort_if($file === null, 404);

        return TicketAttachments::download($file);
    }
}
