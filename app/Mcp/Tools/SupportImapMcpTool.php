<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\ForensicMcpTool;
use App\Models\Scopes\CompanyScope;
use App\Models\TicketDepartment;
use App\Models\TicketPollRun;
use App\Services\Support\Inbound\ImapMailbox;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * «Δουλεύει το IMAP;» — the Support/ticket mailbox health surface (Πυλώνας E,
 * Phase 3b). Per tenant + department: is a mailbox configured, the LAST poll run
 * (connected / fetched / processed / errors + when), and — with `test=true` — a
 * LIVE read-only connect+login test against the stored credentials. So an operator
 * can verify a mailbox works right after setting credentials, without a deploy or
 * grepping the log. Read-only, super-admin, cross-tenant.
 */
#[Name('support_imap')]
#[Description('Support/ticket IMAP mailbox health (Πυλώνας E). Per tenant + department: is a mailbox configured, the last poll run (connected/fetched/processed/errors + when), and — with test=true — a LIVE read-only connect+login test against the stored credentials (network I/O). Optional `company` (slug); omit to span every tenant. Read-only, super-admin. Use it to confirm credentials work after setting them, without a deploy.')]
#[IsReadOnly]
#[IsIdempotent]
class SupportImapMcpTool extends ForensicMcpTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'company' => $schema->string()
                ->description('Optional company slug to focus on. Omit to span every tenant you can reach.'),
            'test' => $schema->boolean()
                ->description('Run a LIVE connect+login test per configured department (network I/O). Default false = stored config + last poll only.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $company = $request->get('company');
        $scope = $this->scope($request, is_string($company) ? $company : null);
        if ($scope['error'] !== null) {
            return self::json(['error' => $scope['error']]);
        }

        $runLive = (bool) $request->get('test');
        $mailbox = app(ImapMailbox::class);

        $companies = [];
        foreach ($scope['companies'] as $tenant) {
            $departments = TicketDepartment::query()
                ->withoutGlobalScope(CompanyScope::class)
                ->where('company_id', $tenant->id)
                ->orderBy('name')
                ->get();

            $rows = [];
            foreach ($departments as $department) {
                $last = TicketPollRun::query()
                    ->withoutGlobalScope(CompanyScope::class)
                    ->where('company_id', $tenant->id)
                    ->where('ticket_department_id', $department->id)
                    ->latest('id')
                    ->first();

                $row = [
                    'department' => $department->name,
                    'email' => $department->email,
                    'configured' => $department->canPollMail(),
                    'host' => $department->imap_host,
                    'last_poll' => $last === null ? null : [
                        'at' => $last->created_at?->toDateTimeString(),
                        'connected' => $last->connected,
                        'fetched' => $last->fetched,
                        'processed' => $last->processed,
                        'errors' => $last->errors ?? [],
                    ],
                ];

                if ($runLive && $department->canPollMail()) {
                    $row['live_test'] = $mailbox->test($department)->toArray();
                }

                $rows[] = $row;
            }

            $companies[$tenant->slug] = [
                'support_enabled' => (bool) $tenant->support_enabled,
                'departments' => $rows,
            ];
        }

        return self::json(['companies' => $companies]);
    }
}
