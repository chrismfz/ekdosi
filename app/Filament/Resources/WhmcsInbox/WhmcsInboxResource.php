<?php

namespace App\Filament\Resources\WhmcsInbox;

use App\Filament\Resources\WhmcsInbox\Pages\ListWhmcsInbox;
use App\Filament\Resources\WhmcsInbox\Tables\WhmcsInboxTable;
use App\Models\PendingWhmcsInvoice;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Stage B-2: operator-facing inbox of WHMCS invoices staged by Stage B-1
 * for review + filing at AADE.
 *
 * Read-only list (no Create / Edit / View pages) - rows arrive via the
 * Stage B-1 ingestion paths (artisan command, webhook) and are acted
 * on via per-row actions:
 *   - File at AADE: full preview modal, then build Invoice + InvoiceLines
 *     + submit via MyDataSubmitter. On success: status -> filed + MARK.
 *   - Reject: status -> rejected, optional reason
 *   - Hold: status -> held (hidden from default filter)
 *   - Re-stage: rejected/held -> pending_review
 *
 * Lives in the "Data" navigation group (same as Firebird Import). Badge
 * shows the pending count so operators see attention-needed at a glance.
 */
class WhmcsInboxResource extends Resource
{
    protected static ?string $model = PendingWhmcsInvoice::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-inbox-arrow-down';

    protected static string|UnitEnum|null $navigationGroup = 'Data';

    // Bridges/Connectors: the inbox is source-neutral in the UI (one «Εισερχόμενα»
    // for every billing bridge; each row carries its source badge). The model +
    // table names stay WHMCS-specific until a real 2nd source justifies the rename
    // (Phase 1) — relabel now is cheap, table/service rename is not.
    protected static ?string $navigationLabel = 'Εισερχόμενα';

    protected static ?string $modelLabel = 'εισερχόμενο παραστατικό';

    protected static ?string $pluralModelLabel = 'Εισερχόμενα';

    protected static ?int $navigationSort = 80;

    protected static ?string $recordTitleAttribute = 'whmcs_invoice_id';

    /**
     * Navigation badge: count of pending_review rows for the current
     * tenant. Surfaces "X invoices waiting for review" without the
     * operator needing to click into the inbox.
     */
    public static function getNavigationBadge(): ?string
    {
        $tenant = Filament::getTenant();
        if (! $tenant) {
            return null;
        }
        $count = PendingWhmcsInvoice::query()
            ->where('company_id', $tenant->getKey())
            ->where('status', PendingWhmcsInvoice::STATUS_PENDING_REVIEW)
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    // Access is governed by PendingWhmcsInvoicePolicy (ViewAny:PendingWhmcsInvoice
    // …) — no canAccess override. `PendingWhmcsInvoice` is in the operator role's
    // curated set, so operators work the inbox; the per-record File-at-AADE /
    // Reject / Hold / Re-stage actions still require Update:PendingWhmcsInvoice.
    // A missing-permission user falls through to a clean Gate deny (false, not a
    // PermissionDoesNotExist throw), so no 404 storm before shield:generate runs.

    public static function table(Table $table): Table
    {
        return WhmcsInboxTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWhmcsInbox::route('/'),
        ];
    }
}
