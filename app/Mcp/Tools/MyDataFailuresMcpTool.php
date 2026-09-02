<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\ForensicMcpTool;
use App\Models\MyDataMark;
use App\Models\Scopes\CompanyScope;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * «Τι χαλάει τώρα;» without knowing which document. The filing path writes a
 * forensic mydata_marks row for every rejection and transport failure —
 * REJECTED / CANCEL_REJECTED / PROVIDER_REJECTED / PROVIDER_FAILED /
 * PROVIDER_CANCEL_(FAILED|REJECTED) — carrying the payload it tried and the
 * provider/AADE reply. This lists the recent ones newest-first, with the
 * business-error codes extracted from each response, so a batch of «[229]»
 * rejections is one glance. Read-only, super_admin, cross-tenant.
 */
#[Name('mydata_failures')]
#[Description('Recent FAILED / REJECTED myDATA/provider filing attempts, newest first — the "what is broken right now?" view across tenants. Reads the forensic mydata_marks rows (REJECTED, CANCEL_REJECTED, PROVIDER_REJECTED, PROVIDER_FAILED, PROVIDER_CANCEL_FAILED/REJECTED) and shows the invoice invcode, action, provider, extracted AADE/InvoSign error codes and the head of the response. Optional `company` (slug), `limit` (default 25, max 100), `days` (lookback window). Use invoice_filing for one document\'s full XML. Read-only, super-admin.')]
#[IsReadOnly]
#[IsIdempotent]
class MyDataFailuresMcpTool extends ForensicMcpTool
{
    /** The forensic non-success actions the submitters persist. */
    private const FAILURE_ACTIONS = [
        'REJECTED',
        'CANCEL_REJECTED',
        'PROVIDER_REJECTED',
        'PROVIDER_FAILED',
        'PROVIDER_CANCEL_FAILED',
        'PROVIDER_CANCEL_REJECTED',
    ];

    public function schema(JsonSchema $schema): array
    {
        return [
            'company' => $schema->string()
                ->description('Optional company slug to focus on. Omit to span every tenant you can reach.'),
            'limit' => $schema->integer()
                ->description('How many recent failures to return (default 25, max 100).'),
            'days' => $schema->integer()
                ->description('Only failures from the last N days. Optional (default: no lower bound).'),
        ];
    }

    public function handle(Request $request): Response
    {
        $company = $request->get('company');
        $scope = $this->scope($request, is_string($company) ? $company : null);
        if ($scope['error'] !== null) {
            return self::json(['error' => $scope['error']]);
        }

        $limit = max(1, min((int) ($request->get('limit') ?? 25), 100));
        $days = $request->get('days') !== null ? max(1, (int) $request->get('days')) : null;
        $slugs = self::slugMap($scope['companies']);

        $query = MyDataMark::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->whereIn('company_id', self::ids($scope['companies']))
            ->whereIn('mydata_action', self::FAILURE_ACTIONS)
            ->with(['invoice:id,invcode,mydata_state,local_status'])
            ->orderByDesc('id');

        if ($days !== null) {
            $query->where('created_at', '>=', now()->subDays($days));
        }

        $rows = $query->limit($limit)->get();

        $failures = $rows->map(fn (MyDataMark $m): array => [
            'id' => (int) $m->id,
            'company' => $slugs[(int) $m->company_id] ?? (string) $m->company_id,
            'invoice_id' => $m->invoice_id,
            'invcode' => $m->invoice?->invcode,
            'current_state' => $m->invoice?->mydata_state,
            'action' => $m->mydata_action,
            'provider_key' => $m->provider_key,
            'error_codes' => self::errorCodes($m->response),
            'response_head' => self::head($m->response, 600),
            'created_at' => $m->created_at?->toIso8601String(),
        ])->all();

        return self::json([
            'companies' => array_values($slugs),
            'window_days' => $days,
            'limit' => $limit,
            'count' => count($failures),
            'failures' => $failures,
            'note' => 'Πλήρες request/response XML: invoice_filing με include_xml=true (ή mark_id).',
        ]);
    }
}
