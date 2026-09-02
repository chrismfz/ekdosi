<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\ForensicMcpTool;
use App\Models\DeliveryNote;
use App\Models\Invoice;
use App\Models\Scopes\CompanyScope;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Schema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * «Τι έχει κολλήσει σιωπηλά;» — the documents that are neither cleanly filed nor
 * cleanly failed, so nothing surfaces them until an operator goes looking. Three
 * DB-only buckets:
 *   - in_doubt: `mydata_pending_since` set but still no state — a transport
 *     timeout left the filing ambiguous (the direct-channel gate against blind
 *     re-POST);
 *   - finalized_unfiled: an ACTIVE (finalised) native invoice whose type files to
 *     myDATA but that carries no MARK — it should be at AADE and isn't;
 *   - delivery_in_doubt: the same pending-since limbo for delivery notes.
 * Read-only, super_admin, cross-tenant.
 */
#[Name('stuck_documents')]
#[Description('Documents stuck between states, the "what is silently stuck?" view. Three DB-only buckets: in_doubt (invoices with mydata_pending_since set but no myDATA state — an ambiguous transport outcome), finalized_unfiled (active native invoices whose type files to myDATA but have no MARK — should be filed, are not), and delivery_in_doubt (delivery notes in the same limbo). Optional `company` (slug) and `limit` (rows per bucket, default 25, max 100). Read-only, super-admin.')]
#[IsReadOnly]
#[IsIdempotent]
class StuckDocumentsMcpTool extends ForensicMcpTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'company' => $schema->string()
                ->description('Optional company slug to focus on. Omit to span every tenant you can reach.'),
            'limit' => $schema->integer()
                ->description('Max rows per bucket (default 25, max 100).'),
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
        $ids = self::ids($scope['companies']);
        $slugs = self::slugMap($scope['companies']);

        // finalized_unfiled only makes sense for tenants that actually file to
        // myDATA/AADE — a gr-mydata or gr-provider channel. An ee-peppol / none
        // tenant whose invoice types happen to carry a mydata_type would otherwise
        // be reported as "should be filed, is not", a pure false alarm.
        $filingIds = array_values(array_map(
            static fn ($c): int => (int) $c->getKey(),
            array_filter(
                $scope['companies'],
                static fn ($c): bool => in_array($c->einvoice_provider, ['gr-mydata', 'gr-provider'], true),
            ),
        ));

        // --- in_doubt: pending_since armed, state never resolved -----------------
        $inDoubtBase = Invoice::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->whereIn('company_id', $ids)
            ->whereNotNull('mydata_pending_since')
            ->whereNull('mydata_state');
        $inDoubtTotal = (clone $inDoubtBase)->count();
        $inDoubt = $inDoubtBase
            ->with(['invoiceType:id,mydata_type', 'customer:id,name'])
            ->orderBy('mydata_pending_since')
            ->limit($limit)
            ->get()
            ->map(fn (Invoice $i): array => [
                'company' => $slugs[(int) $i->company_id] ?? (string) $i->company_id,
                'id' => (int) $i->id,
                'invcode' => $i->invcode,
                'type' => $i->invoiceType?->mydata_type,
                'customer' => $i->customer?->name,
                'gross_total' => $i->gross_total,
                'pending_since' => $i->mydata_pending_since?->toIso8601String(),
                'pending_minutes' => $i->mydata_pending_since !== null ? (int) $i->mydata_pending_since->diffInMinutes(now()) : null,
            ])->all();

        // --- finalized_unfiled: active, files-to-myDATA type, no MARK ------------
        $unfiledBase = Invoice::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->whereIn('company_id', $filingIds)
            ->whereNull('legacy_id') // native docs only — imported ones filed on the old channel
            ->where('local_status', 'active')
            ->whereNull('mydata_mark')
            ->whereNull('mydata_pending_since')
            ->whereHas('invoiceType', fn ($t) => $t->whereNotNull('mydata_type'));
        $unfiledTotal = $filingIds === [] ? 0 : (clone $unfiledBase)->count();
        $unfiled = $filingIds === [] ? [] : $unfiledBase
            ->with(['invoiceType:id,mydata_type', 'customer:id,name'])
            ->orderByDesc('issued_at')
            ->limit($limit)
            ->get()
            ->map(fn (Invoice $i): array => [
                'company' => $slugs[(int) $i->company_id] ?? (string) $i->company_id,
                'id' => (int) $i->id,
                'invcode' => $i->invcode,
                'type' => $i->invoiceType?->mydata_type,
                'customer' => $i->customer?->name,
                'gross_total' => $i->gross_total,
                'issued_at' => $i->issued_at?->toDateString(),
            ])->all();

        // --- delivery_in_doubt: same limbo for delivery notes --------------------
        $deliveryInDoubtTotal = 0;
        $deliveryInDoubt = [];
        if (Schema::hasColumn('delivery_notes', 'mydata_pending_since')) {
            $deliveryBase = DeliveryNote::query()
                ->withoutGlobalScope(CompanyScope::class)
                ->whereIn('company_id', $ids)
                ->whereNotNull('mydata_pending_since')
                ->whereNull('mydata_state');
            $deliveryInDoubtTotal = (clone $deliveryBase)->count();
            $deliveryInDoubt = $deliveryBase
                ->orderBy('mydata_pending_since')
                ->limit($limit)
                ->get()
                ->map(fn (DeliveryNote $d): array => [
                    'company' => $slugs[(int) $d->company_id] ?? (string) $d->company_id,
                    'id' => (int) $d->id,
                    'invcode' => $d->invcode,
                    'pending_since' => $d->mydata_pending_since?->toIso8601String(),
                    'pending_minutes' => $d->mydata_pending_since !== null ? (int) $d->mydata_pending_since->diffInMinutes(now()) : null,
                ])->all();
        }

        return self::json([
            'companies' => array_values($slugs),
            'in_doubt' => [
                'count' => $inDoubtTotal,
                'showing' => count($inDoubt),
                'rows' => $inDoubt,
                'meaning' => 'Ασαφής έκβαση υποβολής (timeout) — μην ξανα-υποβάλεις χειροκίνητα· ο reconciler υιοθετεί το ΜΑΡΚ αν εκδόθηκε.',
            ],
            'finalized_unfiled' => [
                'count' => $unfiledTotal,
                'showing' => count($unfiled),
                'rows' => $unfiled,
                'meaning' => 'Οριστικοποιημένο, ο τύπος δηλώνεται στη myDATA, αλλά δεν έχει ΜΑΡΚ — θα έπρεπε να έχει υποβληθεί. (Μόνο tenants gr-mydata/gr-provider.)',
            ],
            'delivery_in_doubt' => [
                'count' => $deliveryInDoubtTotal,
                'showing' => count($deliveryInDoubt),
                'rows' => $deliveryInDoubt,
            ],
        ]);
    }
}
