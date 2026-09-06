<?php

namespace App\Http\Controllers\Portal;

use App\Actions\Support\OpenTicket;
use App\Actions\Support\PostTicketMessage;
use App\Http\Controllers\Controller;
use App\Models\CustomerUser;
use App\Models\CustomerUserAccess;
use App\Models\Scopes\CompanyScope;
use App\Models\Ticket;
use App\Models\TicketDepartment;
use App\Models\TicketMessage;
use App\Services\Portal\CustomerDocumentFeed;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * «Τα αιτήματά μου» — the customer-facing ticket surface (Πυλώνας E, Phase 2).
 *
 * Scoping is the whole ballgame: a portal login (CustomerUser) may hold grants to
 * several (company, customer) tuples, and it must ONLY ever see/act on tickets of
 * a tuple it actively holds. Every method resolves through
 * {@see CustomerDocumentFeed::grantedTargets()} (the one canonical portal scope)
 * and fails closed (404) on anything unmatched — so a guessed ticket id, a crafted
 * department, or another customer's ticket is unreachable. Off-panel we drop the
 * CompanyScope global scope and filter by the grant's company_id/customer_id
 * explicitly. The portal only ever renders {@see Ticket::publicMessages()} — an
 * internal note never leaves the operator side.
 */
class TicketController extends Controller
{
    public function __construct(
        private readonly CustomerDocumentFeed $feed,
        private readonly OpenTicket $openTicket,
        private readonly PostTicketMessage $postMessage,
    ) {}

    public function index(): View
    {
        $login = $this->login();

        $tickets = collect($this->feed->grantedTargets($login))
            ->flatMap(fn (CustomerUserAccess $grant): iterable => Ticket::query()
                ->withoutGlobalScope(CompanyScope::class)
                ->where('company_id', $grant->company_id)
                ->where('customer_id', $grant->customer_id)
                ->with('department')
                ->get())
            ->sortByDesc(fn (Ticket $t): string => (string) ($t->last_reply_at ?? $t->created_at))
            ->values();

        return view('portal.tickets.index', ['user' => $login, 'tickets' => $tickets]);
    }

    public function create(): View
    {
        $login = $this->login();

        // Offerable departments PER granted (company, customer). Each option carries
        // both ids so store() can re-verify the pairing — never trust a bare id.
        $options = [];
        foreach ($this->feed->grantedTargets($login) as $grant) {
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

        return redirect()
            ->route('portal.tickets.show', $ticket->id)
            ->with('status', 'Το αίτημα καταχωρήθηκε — θα ειδοποιηθείτε για την απάντηση.');
    }

    public function show(int $ticket): View
    {
        $login = $this->login();
        $model = $this->resolveTicket($login, $ticket);
        $model->load(['department', 'publicMessages']);

        return view('portal.tickets.show', ['user' => $login, 'ticket' => $model]);
    }

    public function reply(Request $request, int $ticket): RedirectResponse
    {
        $login = $this->login();
        $model = $this->resolveTicket($login, $ticket);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
        ]);

        $this->postMessage->handle($model, [
            'author_role' => TicketMessage::ROLE_CUSTOMER,
            'author_id' => $model->customer_id,
            'is_internal_note' => false,
            'via' => TicketMessage::VIA_PORTAL,
            'body' => $data['body'],
        ]);

        return redirect()
            ->route('portal.tickets.show', $model->id)
            ->with('status', 'Η απάντησή σας στάλθηκε.');
    }

    private function login(): CustomerUser
    {
        $login = Auth::guard('portal')->user();
        abort_if(! $login instanceof CustomerUser, 403);

        return $login;
    }

    /** The active grant for a customer id, or 404 (fail-closed). */
    private function resolveGrant(CustomerUser $login, int $customerId): CustomerUserAccess
    {
        foreach ($this->feed->grantedTargets($login) as $grant) {
            if ((int) $grant->customer_id === $customerId) {
                return $grant;
            }
        }

        abort(404);
    }

    /** A ticket the login actually holds a grant to, or 404 (fail-closed). */
    private function resolveTicket(CustomerUser $login, int $ticketId): Ticket
    {
        foreach ($this->feed->grantedTargets($login) as $grant) {
            $ticket = Ticket::query()
                ->withoutGlobalScope(CompanyScope::class)
                ->where('company_id', $grant->company_id)
                ->where('customer_id', $grant->customer_id)
                ->find($ticketId);

            if ($ticket !== null) {
                return $ticket;
            }
        }

        abort(404);
    }
}
