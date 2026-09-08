<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\ForensicMcpTool;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Scopes\CompanyScope;
use App\Services\MyData\ReconciliationRow;
use App\Services\MyData\SalesReconciler;
use App\Support\OperatorHealth\HealthKeys;
use Carbon\Carbon;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Cache;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Throwable;

/**
 * Makes the `app_health` discrepancy COUNT actionable — «ποιες είναι οι 92;».
 * Two layers, cheapest first:
 *   - always (DB-only): the last scheduled reconcile's cached count per tenant,
 *     plus the local phase-1 state contradictions (local_status vs mydata_state)
 *     as ROWS — instant, no AADE call;
 *   - `live=true` (opt-in): a real {@see SalesReconciler} pull for the window,
 *     returning the full bucket breakdown (stateMismatch / contentMismatch /
 *     realMissingAtAade / missingLocally / duplicateLocal) with sample rows. This
 *     is the same network call the daily `mydata:reconcile-sales` runs.
 * Read-only, super_admin, cross-tenant.
 */
#[Name('mydata_discrepancies')]
#[Description('Turn the app_health discrepancy COUNT into rows — "which documents disagree with AADE?". Cheap by default: the last scheduled reconcile count per tenant PLUS the local phase-1 state contradictions (locally cancelled but VALID at AADE, or active but CANCELLED at AADE) as actual rows, DB-only. With `live=true` it runs a real AADE reconciliation for the window (default last month; `from`/`to` as YYYY-MM-DD) and returns every bucket with sample rows — slower, needs myDATA read credentials. Optional `company` (slug), `limit` (rows per bucket, default 20, max 100). Read-only, super-admin.')]
#[IsReadOnly]
#[IsIdempotent]
class MyDataDiscrepanciesMcpTool extends ForensicMcpTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'company' => $schema->string()
                ->description('Optional company slug to focus on. Omit to span every tenant you can reach.'),
            'live' => $schema->boolean()
                ->description('Run a real AADE reconciliation (network, credentialed). Default false = the cached count + the local DB-only mismatches only.'),
            'from' => $schema->string()
                ->description('Live window start (YYYY-MM-DD). Only with live=true. Default: one month ago.'),
            'to' => $schema->string()
                ->description('Live window end (YYYY-MM-DD). Only with live=true. Default: today.'),
            'limit' => $schema->integer()
                ->description('Max sample rows per bucket (default 20, max 100).'),
        ];
    }

    public function handle(Request $request): Response
    {
        $company = $request->get('company');
        $scope = $this->scope($request, is_string($company) ? $company : null);
        if ($scope['error'] !== null) {
            return self::json(['error' => $scope['error']]);
        }

        $live = (bool) ($request->get('live') ?? false);
        $limit = max(1, min((int) ($request->get('limit') ?? 20), 100));
        $from = $this->date($request->get('from'));
        $to = $this->date($request->get('to'));

        $tenants = [];
        foreach ($scope['companies'] as $tenant) {
            $tenants[] = $this->forTenant($tenant, $live, $from, $to, $limit);
        }

        return self::json([
            'mode' => $live ? 'live (AADE reconciliation)' : 'cached count + local phase-1',
            'tenants' => $tenants,
            'note' => $live
                ? 'Πλήρες XML ενός παραστατικού: invoice_filing.'
                : 'Για διασταύρωση με AADE (buckets missingAtAade/contentMismatch κ.λπ.) τρέξε ξανά με live=true.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function forTenant(Company $tenant, bool $live, ?Carbon $from, ?Carbon $to, int $limit): array
    {
        $cached = Cache::get(HealthKeys::myDataReconcile((int) $tenant->getKey()), []);
        $cached = is_array($cached) ? $cached : [];

        $out = [
            'company' => (string) $tenant->slug,
            'last_reconcile' => [
                'status' => $cached['status'] ?? 'never run',
                'discrepancies' => $cached['discrepancies'] ?? null,
                'checked_at' => $cached['checked_at'] ?? null,
            ],
            'local_state_mismatch' => $this->localMismatch($tenant, $limit),
        ];

        if ($live) {
            $out['live'] = $this->liveReconcile($tenant, $from, $to, $limit);
        }

        return $out;
    }

    /**
     * Phase-1, DB-only: local_status vs mydata_state contradictions — the exact
     * predicate the in-panel «Έλεγχος myDATA» page uses.
     *
     * @return array<string, mixed>
     */
    private function localMismatch(Company $tenant, int $limit): array
    {
        $query = Invoice::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $tenant->getKey())
            ->where(function ($q) {
                $q->where(fn ($w) => $w->where('local_status', 'cancelled')->where('mydata_state', 'VALID'))
                    ->orWhere(fn ($w) => $w->where('local_status', 'active')->where('mydata_state', 'CANCELLED'));
            });

        $total = (clone $query)->count();

        $rows = $query->with('customer:id,name')
            ->orderByDesc('issued_at')
            ->limit($limit)
            ->get()
            ->map(fn (Invoice $i): array => [
                'id' => (int) $i->id,
                'invcode' => $i->invcode,
                'customer' => $i->customer?->name,
                'gross_total' => $i->gross_total,
                'local_status' => $i->local_status,
                'mydata_state' => $i->mydata_state,
                'problem' => $i->local_status === 'cancelled' && $i->mydata_state === 'VALID'
                    ? 'Ακυρώθηκε τοπικά — χρειάζεται ακύρωση στο myDATA'
                    : 'Ενεργό τοπικά ενώ έχει ακυρωθεί στο myDATA — επανέκδοση',
            ])->all();

        return ['count' => $total, 'showing' => count($rows), 'rows' => $rows];
    }

    /**
     * @return array<string, mixed>
     */
    private function liveReconcile(Company $tenant, ?Carbon $from, ?Carbon $to, int $limit): array
    {
        try {
            $result = (new SalesReconciler($tenant))->reconcile($from, $to);
        } catch (Throwable $e) {
            return ['error' => 'Η ζωντανή διασταύρωση απέτυχε: '.$e->getMessage()];
        }

        return [
            'window' => ['from' => $result->from, 'to' => $result->to],
            'aade_total' => $result->aadeTotal,
            'local_total' => $result->localTotal,
            'discrepancy_count' => $result->discrepancyCount(),
            'buckets' => [
                'stateMismatch' => $this->bucket($result->stateMismatch, $limit),
                'contentMismatch' => $this->bucket($result->contentMismatch, $limit),
                'contentIncomplete' => $this->bucket($result->contentIncomplete, $limit),
                'realMissingAtAade' => $this->bucket($result->realMissingAtAade(), $limit),
                'missingLocally' => $this->bucket($result->missingLocally, $limit),
                'duplicateLocal' => $this->bucket($result->duplicateLocal, $limit),
            ],
        ];
    }

    /**
     * @param  list<ReconciliationRow>  $rows
     * @return array<string, mixed>
     */
    private function bucket(array $rows, int $limit): array
    {
        $sample = array_slice($rows, 0, $limit);

        return [
            'count' => count($rows),
            'rows' => array_map(fn (ReconciliationRow $r): array => [
                'mark' => $r->mark,
                'invcode' => $r->invcode,
                'issued_at' => $r->issuedAt,
                'counterpart' => $r->counterpartName,
                'counterpart_vat' => $r->counterpartVat,
                'gross' => $r->gross,
                'local_state' => $r->localState,
                'aade_state' => $r->aadeState,
                'type' => $r->invoiceType,
                'problem' => $r->problem,
            ], $sample),
        ];
    }

    private function date(mixed $v): ?Carbon
    {
        if (! is_string($v) || trim($v) === '') {
            return null;
        }

        try {
            return Carbon::parse(trim($v));
        } catch (Throwable) {
            return null;
        }
    }
}
