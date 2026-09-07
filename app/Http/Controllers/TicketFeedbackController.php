<?php

namespace App\Http\Controllers;

use App\Jobs\SendTicketFeedbackInvite;
use App\Models\Scopes\CompanyScope;
use App\Models\Ticket;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/**
 * Public, SIGNED customer-satisfaction rating (Πυλώνας E, Phase 4 follow-up). The
 * link is emailed on close ({@see SendTicketFeedbackInvite}); its
 * signature IS the authorization, so no portal login is needed — a customer who
 * never revisits the portal can still rate. Off-panel, so every read is explicit
 * (`withoutGlobalScope`), and rating is gated on {@see Ticket::canBeRated}.
 */
class TicketFeedbackController extends Controller
{
    public function show(int $ticket): View|RedirectResponse
    {
        $model = $this->resolve($ticket);

        return view('support.feedback', [
            'ticket' => $model,
            'ratable' => $model->canBeRated(),
            // Short-lived (the operator is on the page now) — bounds replay of the POST.
            'storeUrl' => URL::temporarySignedRoute('support.feedback.store', now()->addHours(2), ['ticket' => $model->id]),
        ]);
    }

    public function store(Request $request, int $ticket): RedirectResponse
    {
        $model = $this->resolve($ticket);

        // Only a closed ticket of a feedback-enabled (non-merged) department is ratable.
        abort_unless($model->canBeRated(), 404);

        $data = $request->validate([
            'rating' => ['required', 'integer', 'between:1,5'],
            'rating_comment' => ['nullable', 'string', 'max:2000'],
        ]);

        $model->recordRating((int) $data['rating'], $data['rating_comment'] ?? null);

        return redirect()
            ->to(URL::temporarySignedRoute('support.feedback.show', now()->addHours(2), ['ticket' => $model->id]))
            ->with('status', 'Ευχαριστούμε για την αξιολόγηση!');
    }

    /** The ticket the signed link points at, of a support-enabled tenant, or 404. */
    private function resolve(int $ticket): Ticket
    {
        $model = Ticket::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->with(['department', 'company'])
            ->find($ticket);

        abort_if($model === null || ! ($model->company?->hasSupport()), 404);

        return $model;
    }
}
