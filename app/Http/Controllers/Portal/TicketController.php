<?php

namespace App\Http\Controllers\Portal;

use App\Actions\Support\OpenTicket;
use App\Actions\Support\PostTicketMessage;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CustomerUser;
use App\Models\CustomerUserAccess;
use App\Models\Scopes\CompanyScope;
use App\Models\Ticket;
use App\Models\TicketDepartment;
use App\Models\TicketMessage;
use App\Services\Portal\CustomerDocumentFeed;
use App\Support\TicketAttachments;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * «Τα αιτήματά μου» — the customer-facing ticket surface (Πυλώνας E, Phase 2).
 *
 * Scoping is the whole ballgame. A portal login (CustomerUser) may hold grants to
 * several (company, customer) tuples; it must ONLY ever see/act on tickets of a
 * tuple it actively holds AND whose company has the Support pillar enabled. All
 * five actions route through {@see supportedGrants()} (grantedTargets ∩
 * hasSupport) and {@see scopedTicketQuery()} — the ONE place the leak-proof
 * (company_id, customer_id) scope is defined — and fail closed (404) on anything
 * unmatched. Off-panel we drop the CompanyScope global scope and filter
 * explicitly. The portal only ever renders {@see Ticket::publicMessages()} — an
 * internal note never leaves the operator side.
 */
class TicketController extends Controller
{
    /** Cap the customer's own ticket list (matches the documents feed's bounded reads). */
    private const MAX_ROWS = 200;

    public function __construct(
        private readonly CustomerDocumentFeed $feed,
        private readonly OpenTicket $openTicket,
        private readonly PostTicketMessage $postMessage,
    ) {}

    public function index(): View
    {
        $login = $this->login();

        $tickets = $this->scopedTicketQuery($this->supportedGrants($login))
            ->whereNull('merged_into_id') // a merged duplicate lives on in its survivor
            ->with('department')
            ->orderByDesc('last_reply_at')
            ->orderByDesc('id')
            ->limit(self::MAX_ROWS)
            ->get();

        return view('portal.tickets.index', ['user' => $login, 'tickets' => $tickets]);
    }

    public function create(): View
    {
        $login = $this->login();

        // Offerable departments PER supported (company, customer). Each option carries
        // both ids so store() can re-verify the pairing — never trust a bare id.
        $options = [];
        foreach ($this->supportedGrants($login) as $grant) {
            $departments = TicketDepartment::query()
                ->withoutGlobalScope(CompanyScope::class)
                ->where('company_id', $grant->company_id)
                ->where('is_active', true)
                ->where('is_hidden', false)
                ->orderBy('sort')
                ->orderBy('name')
                ->get(['id', 'name']);

            foreach ($departments as $department) {
                $options[] = [
                    'value' => $grant->customer_id.'_'.$department->id,
                    'label' => $grant->customer->name.' · '.$department->name,
                ];
            }
        }

        return view('portal.tickets.create', ['user' => $login, 'options' => $options]);
    }

    public function store(Request $request): RedirectResponse
    {
        $login = $this->login();

        $data = $request->validate([
            'target' => ['required', 'string'],
            'subject' => ['required', 'string', 'max:191'],
            'priority' => ['required', Rule::in(['low', 'normal', 'high'])],
            'body' => ['required', 'string', 'max:5000'],
            'attachments' => ['nullable', 'array', 'max:'.TicketAttachments::MAX_COUNT],
            'attachments.*' => TicketAttachments::fileRules(),
        ]);

        [$customerId, $departmentId] = array_pad(explode('_', $data['target'], 2), 2, null);
        $grant = $this->resolveGrant($login, (int) $customerId);

        // The department must belong to the grant's company AND be offerable.
        $department = TicketDepartment::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $grant->company_id)
            ->where('is_active', true)
            ->where('is_hidden', false)
            ->find((int) $departmentId);
        abort_if($department === null, 404);

        $ticket = $this->openTicket->handle([
            'company_id' => $grant->company_id,
            'customer_id' => $grant->customer_id,
            'ticket_department_id' => $department->id,
            'subject' => $data['subject'],
            'priority' => $data['priority'],
            'opened_via' => 'portal',
            'author_role' => TicketMessage::ROLE_CUSTOMER,
            'author_id' => $grant->customer_id,
            'via' => TicketMessage::VIA_PORTAL,
            'body' => $data['body'],
        ]);

        // Attach any uploaded files to the opening message (customer = no uploader id).
        if ($request->hasFile('attachments')) {
            TicketAttachments::storeUploaded($ticket->messages()->first(), $request->file('attachments'));
        }

        return redirect()
            ->route('portal.tickets.show', $ticket->id)
            ->with('status', __('portal.flash.ticket_created'));
    }

    public function show(int $ticket): View|RedirectResponse
    {
        $login = $this->login();
        $model = $this->resolveTicket($login, $ticket);

        // A merged duplicate has no thread of its own — send the customer to the
        // survivor (guaranteed same owner, so resolveTicket there also succeeds).
        if ($model->merged_into_id !== null) {
            return redirect()->route('portal.tickets.show', $model->merged_into_id);
        }

        $model->load(['department', 'publicMessages.attachments']);

        return view('portal.tickets.show', ['user' => $login, 'ticket' => $model]);
    }

    public function reply(Request $request, int $ticket): RedirectResponse
    {
        $login = $this->login();
        $model = $this->resolveTicket($login, $ticket);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
            'attachments' => ['nullable', 'array', 'max:'.TicketAttachments::MAX_COUNT],
            'attachments.*' => TicketAttachments::fileRules(),
        ]);

        $message = $this->postMessage->handle($model, [
            'author_role' => TicketMessage::ROLE_CUSTOMER,
            'author_id' => $model->customer_id,
            'is_internal_note' => false,
            'via' => TicketMessage::VIA_PORTAL,
            'body' => $data['body'],
        ]);

        if ($request->hasFile('attachments')) {
            TicketAttachments::storeUploaded($message, $request->file('attachments'));
        }

        return redirect()
            ->route('portal.tickets.show', $model->id)
            ->with('status', __('portal.flash.reply_sent'));
    }

    public function rate(Request $request, int $ticket): RedirectResponse
    {
        $login = $this->login();
        $model = $this->resolveTicket($login, $ticket);
        $model->loadMissing('department');

        // Fail-closed: rating is only offered on a closed ticket whose department
        // invites feedback (`feedback_on_close`). Anything else → 404.
        abort_unless($model->canBeRated(), 404);

        $data = $request->validate([
            'rating' => ['required', 'integer', 'between:1,5'],
            'rating_comment' => ['nullable', 'string', 'max:2000'],
        ]);

        $model->recordRating((int) $data['rating'], $data['rating_comment'] ?? null);

        return redirect()
            ->route('portal.tickets.show', $model->id)
            ->with('status', __('portal.flash.rating_thanks'));
    }

    public function attachment(int $ticket, int $attachment): StreamedResponse
    {
        $login = $this->login();
        // Grant-scoped, fail-closed — 404 for a ticket the login doesn't own.
        $model = $this->resolveTicket($login, $ticket);

        // publicOnly: a customer must never reach an internal-note attachment.
        $file = TicketAttachments::forTicket($model, $attachment, publicOnly: true);
        abort_if($file === null, 404);

        return TicketAttachments::download($file);
    }

    private function login(): CustomerUser
    {
        $login = Auth::guard('portal')->user();
        abort_if(! $login instanceof CustomerUser, 403);

        return $login;
    }

    /**
     * The login's active grants, narrowed to companies that have the Support pillar
     * enabled — the same gate the operator SupportCluster uses. A customer of a
     * support-disabled tenant sees/opens NO tickets (they'd be an invisible sink,
     * since the operator area is hidden).
     *
     * @return list<CustomerUserAccess>
     */
    private function supportedGrants(CustomerUser $login): array
    {
        $grants = $this->feed->grantedTargets($login);
        if ($grants === []) {
            return [];
        }

        $enabled = Company::query()
            ->whereIn('id', array_map(static fn (CustomerUserAccess $g): int => (int) $g->company_id, $grants))
            ->where('support_enabled', true)
            ->pluck('id')
            ->flip();

        return array_values(array_filter(
            $grants,
            static fn (CustomerUserAccess $g): bool => $enabled->has((int) $g->company_id),
        ));
    }

    /**
     * A Ticket query scoped to the given grants' (company_id, customer_id) tuples —
     * the ONE definition of the portal's ticket boundary. Empty grants ⇒ matches
     * nothing (fail-closed), never an unconstrained query.
     *
     * @param  list<CustomerUserAccess>  $grants
     */
    private function scopedTicketQuery(array $grants): Builder
    {
        $query = Ticket::query()->withoutGlobalScope(CompanyScope::class);

        if ($grants === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $outer) use ($grants): void {
            foreach ($grants as $grant) {
                $outer->orWhere(fn (Builder $inner): Builder => $inner
                    ->where('company_id', $grant->company_id)
                    ->where('customer_id', $grant->customer_id));
            }
        });
    }

    /** The active, support-enabled grant for a customer id, or 404 (fail-closed). */
    private function resolveGrant(CustomerUser $login, int $customerId): CustomerUserAccess
    {
        foreach ($this->supportedGrants($login) as $grant) {
            if ((int) $grant->customer_id === $customerId) {
                return $grant;
            }
        }

        abort(404);
    }

    /** A ticket the login holds a support-enabled grant to, or 404 (fail-closed). */
    private function resolveTicket(CustomerUser $login, int $ticketId): Ticket
    {
        $ticket = $this->scopedTicketQuery($this->supportedGrants($login))->find($ticketId);
        abort_if($ticket === null, 404);

        return $ticket;
    }
}
