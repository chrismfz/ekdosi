<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\ForensicMcpTool;
use App\Models\Invoice;
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
 * The one that turns «γιατί έσκασε αυτό;» into a single call. By invcode (ΤΠΥ6660)
 * or numeric id: the invoice's local vs myDATA state, and the FULL mydata_marks
 * history — every INSERT / CANCEL / REJECTED / *_FAILED attempt with its MARK,
 * cancellation MARK, provider, authentication code and timestamps. With
 * `include_xml` (or a specific `mark_id`) it returns the byte-exact request +
 * response XML that produced a row — the legal record, the real debugging surface.
 * Cross-tenant, read-only, super_admin.
 */
#[Name('invoice_filing')]
#[Description('Full myDATA/provider filing history of ONE invoice — the first call for "why was this document rejected / stuck?". By `invoice` = invcode (e.g. "ΤΠΥ6660") or numeric id. Returns local_status × mydata_state, the mirror MARK/URL, and every mydata_marks row (action INSERT/CANCEL/REJECTED/PROVIDER_*/*_FAILED, MARK, cancellation MARK, provider, auth code, extracted error codes, timestamps). Set `include_xml=true` for the bounded request/response XML of each row, or `mark_id` to dump ONE row\'s full XML. Optional `company` (slug) disambiguates an invcode shared across tenants. Read-only, super-admin.')]
#[IsReadOnly]
#[IsIdempotent]
class InvoiceFilingMcpTool extends ForensicMcpTool
{
    private const MAX_XML = 60000; // per XML field, when include_xml/mark_id asks for it

    public function schema(JsonSchema $schema): array
    {
        return [
            'invoice' => $schema->string()
                ->description('The invoice: its invcode (e.g. "ΤΠΥ6660") or its numeric id.')
                ->required(),
            'company' => $schema->string()
                ->description('Optional company slug — needed only to disambiguate an invcode that exists in more than one tenant. Omit to search every tenant you can reach.'),
            'include_xml' => $schema->boolean()
                ->description('Include the (bounded) request + response XML of EVERY mark row. Default false — the row summary + error codes usually suffice.'),
            'mark_id' => $schema->integer()
                ->description('Return the full request/response XML of just this one mydata_marks row id (overrides include_xml for size).'),
        ];
    }

    public function handle(Request $request): Response
    {
        $scope = $this->scope($request, $this->stringArg($request, 'company'));
        if ($scope['error'] !== null) {
            return self::json(['error' => $scope['error']]);
        }

        $needle = trim((string) ($request->get('invoice') ?? ''));
        if ($needle === '') {
            return self::json(['error' => 'Δώσε `invoice` (invcode ή αριθμητικό id).']);
        }

        $includeXml = (bool) ($request->get('include_xml') ?? false);
        $markId = $request->get('mark_id') !== null ? (int) $request->get('mark_id') : null;

        $ids = self::ids($scope['companies']);
        $slugs = self::slugMap($scope['companies']);

        // CLI/queue context: the CompanyScope is a no-op with no ambient tenant, so
        // filter explicitly by the resolved, access-checked company set (declare the
        // cross-tenant sweep by dropping the scope, then re-constrain by id).
        $query = Invoice::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->whereIn('company_id', $ids)
            ->with(['invoiceType', 'customer']);

        if (ctype_digit($needle)) {
            $query->where('id', (int) $needle);
        } else {
            $query->where('invcode', $needle);
        }

        $invoices = $query->orderByDesc('id')->limit(10)->get();

        if ($invoices->isEmpty()) {
            return self::json([
                'error' => "Δεν βρέθηκε παραστατικό «{$needle}» στις εταιρείες που έχεις πρόσβαση.",
                'searched_companies' => array_values($slugs),
            ]);
        }

        $results = $invoices->map(function (Invoice $invoice) use ($slugs, $includeXml, $markId): array {
            $marks = MyDataMark::query()
                ->withoutGlobalScope(CompanyScope::class)
                ->where('invoice_id', $invoice->id)
                ->orderBy('id')
                ->get();

            return [
                'company' => $slugs[(int) $invoice->company_id] ?? (string) $invoice->company_id,
                'id' => (int) $invoice->id,
                'invcode' => $invoice->invcode,
                'legacy_id' => $invoice->legacy_id,
                'issued_at' => $invoice->issued_at?->toDateString(),
                'invoice_type' => [
                    'code' => $invoice->invoiceType?->code,
                    'mydata_type' => $invoice->invoiceType?->mydata_type,
                ],
                'series' => $invoice->filedSeries(),
                'aa' => $invoice->code,
                'customer' => [
                    'name' => $invoice->customer?->name,
                    'afm' => $invoice->customer?->afm,
                ],
                'net_total' => $invoice->net_total,
                'gross_total' => $invoice->gross_total,
                'local_status' => $invoice->local_status,
                'payment_status' => $invoice->payment_status,
                'mydata' => [
                    'state' => $invoice->mydata_state,
                    'mark' => $invoice->mydata_mark,
                    'url' => $invoice->mydata_url,
                    'sent' => (bool) $invoice->mydata_sent,
                    'pending_since' => $invoice->mydata_pending_since?->toIso8601String(),
                    'type_snapshot' => $invoice->mydata_type,
                ],
                'marks' => $marks->map(fn (MyDataMark $m): array => $this->markRow($m, $includeXml, $markId))->all(),
            ];
        })->all();

        return self::json([
            'query' => $needle,
            'matches' => count($results),
            'invoices' => $results,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function markRow(MyDataMark $m, bool $includeXml, ?int $markId): array
    {
        $wantsXml = $includeXml || ($markId !== null && (int) $m->id === $markId);

        $row = [
            'id' => (int) $m->id,
            'action' => $m->mydata_action,
            'mark' => $m->mark,
            'cancellation_mark' => $m->cancellation_mark,
            'provider_key' => $m->provider_key,
            'authentication_code' => $m->authentication_code,
            'delivery_state' => $m->delivery_state,
            'invoice_url' => $m->invoice_url,
            'error_codes' => self::errorCodes($m->response),
            'mark_date' => $m->mark_date?->toDateString(),
            'mark_time' => $m->mark_time,
            'created_at' => $m->created_at?->toIso8601String(),
        ];

        if ($wantsXml) {
            $row['request_xml'] = mb_substr((string) $m->request, 0, self::MAX_XML);
            $row['response_xml'] = mb_substr((string) $m->response, 0, self::MAX_XML);
        } else {
            $row['response_head'] = self::head($m->response);
        }

        return $row;
    }

    private function stringArg(Request $request, string $key): ?string
    {
        $v = $request->get($key);

        return is_string($v) ? $v : null;
    }
}
