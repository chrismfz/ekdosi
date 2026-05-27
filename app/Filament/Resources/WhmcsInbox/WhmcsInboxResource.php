<?php

namespace App\Filament\Resources\WhmcsInbox;

use App\Filament\Resources\WhmcsInbox\Pages\ListWhmcsInbox;
use App\Filament\Resources\WhmcsInbox\Tables\WhmcsInboxTable;
use App\Models\PendingWhmcsInvoice;
use BackedEnum;
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

    protected static ?string $navigationLabel = 'WHMCS Inbox';

    protected static ?string $modelLabel = 'pending WHMCS invoice';

    protected static ?string $pluralModelLabel = 'WHMCS Inbox';

    protected static ?int $navigationSort = 80;

    protected static ?string $recordTitleAttribute = 'whmcs_invoice_id';

    /**
     * Navigation badge: count of pending_review rows for the current
     * tenant. Surfaces "X invoices waiting for review" without the
     * operator needing to click into the inbox.
     */
    public static function getNavigationBadge(): ?string
    {
        $tenant = \Filament\Facades\Filament::getTenant();
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

    /**
     * Bypass Shield's default deny-when-no-permission-exists behavior
     * the way the Καρτέλα page does. Without this, the gap between
     * deploy and the operator running `php artisan shield:generate
     * --resource=WhmcsInboxResource` reproduces the same 404 storm
     * the CustomerLedger had in PRs #39-45. The per-record actions
     * (File at AADE / Reject / Hold / Re-stage) still consult the
     * PendingWhmcsInvoicePolicy via update authorization, so a
     * permission-less user gets a read-only view of the inbox until
     * permissions are granted — not unrestricted file-at-AADE access.
     */
    public static function canAccess(): bool
    {
        return auth()->check();
    }

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
